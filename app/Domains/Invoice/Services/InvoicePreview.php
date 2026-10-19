<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Contracts\VatTreatments;
use App\Domains\Compliance\Engine\DTOs\VatCategory;

/**
 * What an invoice form adds up to while it is being typed: each line's VAT
 * and total, and the VAT by tax category.
 *
 * The same arithmetic the draft is saved with (InvoiceTotals) and the
 * document declares its breakdown by (InvoiceTotals::categories, the
 * discount shared across the categories and the charges added to theirs),
 * so a mixed basket shows here the figures it is issued with. Nothing is
 * validated or stored: a line not yet well-formed is left out and shows
 * nothing, and the country's rules judge the invoice when it is saved.
 *
 * Only a category the country taxes charges VAT; a line in any other is at
 * zero whatever rate it states, as the country's rules draft it.
 */
class InvoicePreview
{
    /** Bounded as the invoice rules bound the lines and the columns hold amounts. */
    private const MAX_LINES = 1000;

    private const MAX_AMOUNT = '9999999999.99';

    /**
     * @param  array<string, mixed>  $input  the form as it stands
     * @return array{lines: list<array{net: string, tax: string, total: string}|null>, categories: list<array{code: string, label: string, rate: string, reasons: list<string>, base: string, tax: string}>, subtotal: string, discount: string, charges: string, tax: string, total: string}
     */
    public function of(array $input, VatTreatments $treatments): array
    {
        $categories = [];

        foreach ($treatments->categories() as $category) {
            $categories[$category->code] = $category;
        }

        $inclusive = in_array($input['prices_include_vat'] ?? null, ['1', 1, true, 'true', 'on'], true);
        $typed = array_slice(array_values((array) ($input['lines'] ?? [])), 0, self::MAX_LINES);
        $positions = [];
        $lines = [];

        foreach ($typed as $position => $line) {
            $priced = is_array($line) ? $this->line($line, $categories) : null;

            if ($priced !== null) {
                $positions[count($lines)] = $position;
                $lines[] = $priced;
            }
        }

        $charges = array_values(array_filter(array_map(
            fn ($charge): ?array => is_array($charge) ? $this->charge($charge, $categories) : null,
            array_slice(array_values((array) ($input['charges'] ?? [])), 0, self::MAX_LINES),
        )));

        $discount = $this->amount($input['discount_amount'] ?? null) ?? '0';
        $totals = InvoiceTotals::of($lines, $discount, $inclusive, $charges);

        $out = array_fill(0, count($typed), null);

        foreach ($totals->lines as $index => $priced) {
            $out[$positions[$index]] = ['net' => $priced['net'], 'tax' => $priced['tax'], 'total' => $priced['total']];
        }

        return [
            'lines' => $out,
            'categories' => $this->breakdown($lines, $totals, $inclusive, $categories),
            'subtotal' => $totals->subtotal,
            'discount' => $totals->discount,
            'charges' => $totals->chargeTotal,
            'tax' => $totals->tax,
            'total' => $totals->total,
        ];
    }

    /**
     * Each category's base and VAT, as the document's breakdown states it.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, VatCategory>  $categories
     * @return list<array{code: string, label: string, rate: string, reasons: list<string>, base: string, tax: string}>
     */
    private function breakdown(array $lines, InvoiceTotals $totals, bool $inclusive, array $categories): array
    {
        $nets = [];
        $rates = [];
        $codes = [];
        $reasons = [];
        $charges = [];

        foreach ($totals->lines as $index => $priced) {
            $key = InvoiceTotals::key($lines[$index], $priced['rate']);
            $nets[$key] = ($nets[$key] ?? 0.0) + (float) $priced['net'];
            $rates[$key] = (float) $priced['rate'];
            $codes[$key] = (string) $lines[$index]['tax_category'];

            if (filled($lines[$index]['exempt_code'] ?? null)) {
                $reasons[$key][(string) $lines[$index]['exempt_code']] = true;
            }
        }

        foreach ($totals->charges as $charge) {
            $charges[$charge['key']] = ($charges[$charge['key']] ?? 0.0) + (float) $charge['net'];
            $rates[$charge['key']] ??= (float) $charge['rate'];
            $codes[$charge['key']] ??= $charge['category'];
        }

        $breakdown = [];

        foreach (InvoiceTotals::categories($nets, $rates, (float) $totals->discount, $inclusive ? (float) $totals->tax : null, $charges) as $key => $category) {
            $code = $codes[$key];

            $breakdown[] = [
                'code' => $code,
                'label' => isset($categories[$code]) ? $categories[$code]->label : $code,
                'rate' => number_format($rates[$key], 2, '.', ''),
                'reasons' => array_keys($reasons[$key] ?? []),
                'base' => number_format($category['base'], 2, '.', ''),
                'tax' => number_format($category['tax'], 2, '.', ''),
            ];
        }

        return $breakdown;
    }

    /**
     * A line as InvoiceTotals prices it, or null while it is not one yet.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, VatCategory>  $categories
     * @return array<string, mixed>|null
     */
    private function line(array $line, array $categories): ?array
    {
        $quantity = $this->amount($line['quantity'] ?? null);
        $price = $this->amount($line['unit_price'] ?? null);

        if ($quantity === null || $price === null || bccomp($quantity, '0', 3) <= 0) {
            return null;
        }

        $category = $this->category($line, $categories);

        return [
            'quantity' => $quantity,
            'unit_price' => $price,
            'discount_amount' => $this->amount($line['discount_amount'] ?? null) ?? '0',
            'charges' => array_values(array_filter(array_map(fn ($charge): ?array => is_array($charge) ? $this->charge($charge, $categories, false) : null, (array) ($line['charges'] ?? [])))),
            'tax_category' => $category->code,
            'tax_rate' => $this->rate($line, $category),
            'exempt_code' => is_string($line['exempt_code'] ?? null) ? $line['exempt_code'] : null,
        ];
    }

    /**
     * A charge as InvoiceTotals takes it: an amount or a percentage, and for
     * a document charge its category and rate.
     *
     * @param  array<string, mixed>  $charge
     * @param  array<string, VatCategory>  $categories
     * @return array<string, mixed>|null
     */
    private function charge(array $charge, array $categories, bool $document = true): ?array
    {
        $amount = $this->amount($charge['amount'] ?? null);
        $percent = $this->amount($charge['percent'] ?? null);

        if ($amount === null && $percent === null) {
            return null;
        }

        $resolved = ['amount' => $amount, 'percent' => $percent, 'base_amount' => $this->amount($charge['base_amount'] ?? null)];

        if (! $document) {
            return $resolved;
        }

        $category = $this->category($charge, $categories);

        return [...$resolved, 'tax_category' => $category->code, 'tax_rate' => $this->rate($charge, $category)];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, VatCategory>  $categories
     */
    private function category(array $item, array $categories): VatCategory
    {
        $code = is_string($item['tax_category'] ?? null) ? $item['tax_category'] : '';

        return $categories[$code] ?? reset($categories);
    }

    /**
     * The rate a taxed category charges - the one typed, else its own - and
     * zero for every other.
     *
     * @param  array<string, mixed>  $item
     */
    private function rate(array $item, VatCategory $category): string
    {
        if (! $category->taxed) {
            return '0';
        }

        $typed = $item['tax_rate'] ?? null;

        return is_numeric($typed) && $typed >= 0 && $typed <= 100 ? (string) $typed : (string) $category->rate;
    }

    /** A non-negative amount as typed, or null when it is not one. */
    private function amount(mixed $value): ?string
    {
        if (! is_numeric($value) || ! preg_match('/^\d+(\.\d+)?$/', trim((string) $value))) {
            return null;
        }

        $value = trim((string) $value);

        return bccomp($value, self::MAX_AMOUNT, 6) > 0 ? null : $value;
    }
}
