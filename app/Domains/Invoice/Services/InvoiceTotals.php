<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Support\AllowanceApportionment;

/**
 * What an invoice's lines and discount add up to.
 *
 * A line's VAT is its net times its rate, rounded half up to the halalah. The
 * document's VAT is not the sum of those: it is each tax category's net, less
 * that category's share of the document discount, times the category rate.
 * ZATCA checks the document VAT against those category amounts (BR-CO-14),
 * and XmlBuilder declares them through categories(), so both read one rule.
 *
 * Prices may also be entered VAT-inclusive, as a shelf price is. Then the
 * customer's figures are the fixed ones: each line's gross is exact, and so is
 * the document total. The net ZATCA is given is backed out of the gross per
 * tax category, and the document VAT is whatever the gross leaves over the
 * net - ZATCA's own method: "The VAT amount calculated as a fraction of
 * 15/115 of the VAT-inclusive price" (Guideline for Tax Invoicing and
 * Records, v3, 4.2.4, Example 6). Rounding the net and the VAT separately
 * cannot land on every gross - at 15% about one total in eight is out of
 * reach - so where it cannot, the halalah sits in a category's VAT. ZATCA's
 * check of a category's VAT against its base times its rate (BR-S-09,
 * BR-CO-17) allows a halalah per invoice line, so this does not register;
 * the totals rules it enforces as errors (BR-CO-14, BR-CO-15) hold exactly.
 */
final readonly class InvoiceTotals
{
    /**
     * ZATCA's standard VAT rate, applied when a line does not state its own.
     */
    public const DEFAULT_TAX_RATE = '15';

    /**
     * @param  list<array{net: string, unit_price: string, rate: string, tax: string, total: string}>  $lines
     */
    private function __construct(
        public array $lines,
        public string $subtotal,
        public string $discount,
        public string $tax,
        public string $total,
    ) {}

    /**
     * A stored net, when a line carries one, is used rather than recomputed.
     *
     * @param  array<array{quantity: int|float|string, unit_price: int|float|string, net?: string|null, tax_rate?: int|float|string|null, tax_category?: string|null}>  $lines
     * @param  bool  $inclusive  The unit prices and the discount include VAT.
     */
    public static function of(array $lines, string $discount = '0', bool $inclusive = false): self
    {
        return $inclusive ? self::inclusive($lines, $discount) : self::exclusive($lines, $discount);
    }

    /**
     * @param  array<array<string, mixed>>  $lines
     */
    private static function exclusive(array $lines, string $discount): self
    {
        $priced = [];
        $subtotal = '0';
        $nets = [];
        $rates = [];

        foreach ($lines as $line) {
            $rate = (string) ($line['tax_rate'] ?? self::DEFAULT_TAX_RATE);
            $net = isset($line['net'])
                ? bcadd((string) $line['net'], '0', 2)
                : self::round(bcmul((string) $line['quantity'], (string) $line['unit_price'], 6));
            $tax = self::round(bcdiv(bcmul($net, $rate, 6), '100', 6));

            $priced[] = [
                'net' => $net,
                'unit_price' => (string) $line['unit_price'],
                'rate' => $rate,
                'tax' => $tax,
                'total' => bcadd($net, $tax, 2),
            ];
            $subtotal = bcadd($subtotal, $net, 2);

            $key = self::key($line, $rate);
            $nets[$key] = ($nets[$key] ?? 0.0) + (float) $net;
            $rates[$key] = (float) $rate;
        }

        $discount = bcadd($discount, '0', 2);
        $tax = '0';

        foreach (self::categories($nets, $rates, (float) $discount) as $category) {
            $tax = bcadd($tax, number_format($category['tax'], 2, '.', ''), 2);
        }

        return new self($priced, $subtotal, $discount, $tax, bcadd(bcsub($subtotal, $discount, 2), $tax, 2));
    }

    /**
     * Back the net out of gross prices, keeping every figure the customer
     * sees exact: each line's gross and the document total.
     *
     * @param  array<array<string, mixed>>  $lines
     */
    private static function inclusive(array $lines, string $grossDiscount): self
    {
        $lines = array_values($lines);
        $gross = [];
        $byKey = [];
        $rates = [];

        foreach ($lines as $index => $line) {
            $rate = (string) ($line['tax_rate'] ?? self::DEFAULT_TAX_RATE);
            $gross[$index] = self::round(bcmul((string) $line['quantity'], (string) $line['unit_price'], 6));

            $key = self::key($line, $rate);
            $byKey[$key][] = $index;
            $rates[$key] = $rate;
        }

        // Each category's net is decided on its gross and then shared among
        // its lines, so the rounding happens once per category instead of
        // once per line and drifting with the number of lines.
        $nets = [];
        $grossByKey = [];

        foreach ($byKey as $key => $indexes) {
            $categoryGross = '0';
            $largest = $indexes[0];
            $shared = '0';

            foreach ($indexes as $i) {
                $categoryGross = bcadd($categoryGross, $gross[$i], 2);
                $nets[$i] = self::netOf($gross[$i], $rates[$key]);
                $shared = bcadd($shared, $nets[$i], 2);

                if (bccomp($gross[$i], $gross[$largest], 2) > 0) {
                    $largest = $i;
                }
            }

            $grossByKey[$key] = (float) $categoryGross;
            $nets[$largest] = bcadd($nets[$largest], bcsub(self::netOf($categoryGross, $rates[$key]), $shared, 2), 2);
        }

        $priced = [];
        $subtotal = '0';
        $grossTotal = '0';

        foreach ($lines as $index => $line) {
            $quantity = (string) $line['quantity'];

            $priced[] = [
                'net' => $nets[$index],
                // The stored price stays a net price, as everything that
                // reads it assumes. It is rounded; 'net' is the exact figure.
                'unit_price' => bccomp($quantity, '0', 3) > 0
                    ? self::round(bcdiv($nets[$index], $quantity, 6))
                    : '0.00',
                'rate' => (string) ($line['tax_rate'] ?? self::DEFAULT_TAX_RATE),
                'tax' => bcsub($gross[$index], $nets[$index], 2),
                'total' => $gross[$index],
            ];
            $subtotal = bcadd($subtotal, $nets[$index], 2);
            $grossTotal = bcadd($grossTotal, $gross[$index], 2);
        }

        // The discount was given gross. Each category's share of it comes
        // off at that category's rate, and the customer pays the gross less
        // the gross discount, exactly.
        $grossDiscount = bcadd($grossDiscount, '0', 2);
        $discount = '0';

        foreach (AllowanceApportionment::apportion($grossByKey, (float) $grossDiscount) as $key => $share) {
            $discount = bcadd($discount, self::netOf(number_format($share, 2, '.', ''), $rates[$key]), 2);
        }

        $total = bcsub($grossTotal, $grossDiscount, 2);

        return new self($priced, $subtotal, $discount, bcsub($total, bcsub($subtotal, $discount, 2), 2), $total);
    }

    /**
     * Each category's taxable base and VAT once the discount is shared out.
     *
     * @param  array<string, float>  $nets  net amount per category key
     * @param  array<string, float>  $rates  VAT rate per category key
     * @param  float|null  $tax  The document's VAT when it is fixed rather than
     *                           derived - an invoice priced VAT-inclusive. The
     *                           halalah between it and the categories' own
     *                           rounding is carried by the largest category,
     *                           so the categories still add up to it (BR-CO-14).
     * @return array<string, array{share: float, base: float, tax: float}>
     */
    public static function categories(array $nets, array $rates, float $discount, ?float $tax = null): array
    {
        $shares = AllowanceApportionment::apportion($nets, $discount);
        $categories = [];
        $largest = null;

        foreach ($nets as $key => $net) {
            $share = $shares[$key] ?? 0.0;
            $base = round($net - $share, 2);

            $categories[$key] = [
                'share' => $share,
                'base' => $base,
                // In bcmath, half up, as line VAT is: in floating point
                // 4.10 * 15 / 100 is 0.61499..., which rounded to 0.61
                // against a line VAT of 0.62.
                'tax' => (float) self::round(bcdiv(bcmul(number_format($base, 2, '.', ''), (string) $rates[$key], 6), '100', 6)),
            ];

            if ($largest === null || $base > $categories[$largest]['base']) {
                $largest = $key;
            }
        }

        if ($tax !== null && $largest !== null) {
            $residual = round($tax - array_sum(array_column($categories, 'tax')), 2);
            $categories[$largest]['tax'] = round($categories[$largest]['tax'] + $residual, 2);
        }

        return $categories;
    }

    /**
     * The net in a gross amount at a rate, to the halalah.
     */
    private static function netOf(string $gross, string $rate): string
    {
        return self::round(bcdiv(bcmul($gross, '100', 6), bcadd('100', $rate, 6), 6));
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function key(array $line, string $rate): string
    {
        return ($line['tax_category'] ?? 'S').'_'.(float) $rate;
    }

    /**
     * Round half away from zero. bcmath truncates, which understates VAT.
     */
    private static function round(string $value, int $scale = 2): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, str_starts_with($value, '-') ? "-$half" : $half, $scale);
    }
}
