<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Services\VatSummary;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * The period's sales grouped one way at a time - by customer, item,
 * branch, VAT category or month - in the organization's tax currency.
 *
 * The same documents as the VAT return reads (VatSummary::issued: past
 * draft, not refused, not a proforma, by tax point), credit notes taken
 * off, each document converted at its own rate and one without a rate left
 * out and named. Customer, branch, category and month use each document's
 * own split after its discount and prepayments (VatSummary::categories), so
 * they add up to the return; an item's figure is its lines' amounts before
 * any discount on the whole document, which no line carries.
 */
class SalesReports
{
    public const GROUPS = ['customer', 'item', 'branch', 'category', 'month'];

    public function __construct(
        private readonly VatSummary $vat,
        private readonly BooksCountry $country,
    ) {}

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'item' => __('Item'),
            'branch' => __('Branch'),
            'category' => __('VAT category'),
            'month' => __('Month'),
            default => __('Customer'),
        };
    }

    /**
     * @return array{currency: string, rows: list<array{key: string, label: string, documents: int, quantity: float|null, base: float, vat: float, total: float}>, base: float, vat: float, total: float, missing_rate: list<string>}
     */
    public function by(Organization $organization, string $group, CarbonImmutable $from, CarbonImmutable $to, string $sort = 'amount'): array
    {
        $group = in_array($group, self::GROUPS, true) ? $group : 'customer';
        $currency = $this->country->currency($organization);
        $rows = [];
        $missing = [];

        foreach ($this->vat->issued((string) $organization->id, $from, $to) as $invoice) {
            $rate = $this->vat->rate($invoice, $currency);

            if ($rate === null) {
                $missing[] = (string) $invoice->invoice_number;

                continue;
            }

            $sign = $invoice->document_type === DocumentType::CreditNote ? -1 : 1;

            if ($group === 'item') {
                foreach ($invoice->lines as $line) {
                    $key = $line->product_id !== null ? 'p:'.$line->product_id : 'n:'.mb_strtolower(trim((string) ($line->item_name ?: $line->description)));
                    $this->add($rows, $key, (string) ($line->item_name ?: $line->description), (string) $invoice->id,
                        $sign * (float) $line->net_amount * $rate, $sign * (float) $line->tax_amount * $rate, $sign * (float) $line->quantity);
                }

                continue;
            }

            foreach ($this->vat->categories($invoice) as $category) {
                [$key, $label] = match ($group) {
                    'branch' => $invoice->branch_id === null ? ['-', __('Head office')] : ['b:'.$invoice->branch_id, (string) $invoice->branch?->name],
                    'category' => [$category['code'].'|'.$category['exempt'], trim($category['code'].' '.($category['exempt'] ?? ''))],
                    'month' => [$month = CarbonImmutable::parse($invoice->issue_date)->format('Y-m'), $month],
                    default => $invoice->customer_id !== null
                        ? ['c:'.$invoice->customer_id, (string) ($invoice->customer?->name ?: $invoice->buyer_name)]
                        : ['n:'.mb_strtolower(trim((string) $invoice->buyer_name)), (string) ($invoice->buyer_name ?: __('Walk-in customer'))],
                };
                $this->add($rows, $key, $label, (string) $invoice->id, $sign * $category['base'] * $rate, $sign * $category['tax'] * $rate, null);
            }
        }

        $rows = array_values(array_map(fn (array $row): array => [
            'key' => $row['key'],
            'label' => $row['label'],
            'documents' => count($row['documents']),
            'quantity' => $row['quantity'] === null ? null : round($row['quantity'], 3),
            'base' => round($row['base'], 2) + 0.0,
            'vat' => round($row['vat'], 2) + 0.0,
            'total' => round($row['base'] + $row['vat'], 2) + 0.0,
        ], $rows));

        usort($rows, match (true) {
            $group === 'month' || $sort === 'name' => fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']),
            default => fn (array $a, array $b): int => $b['base'] <=> $a['base'],
        });

        return [
            'currency' => $currency,
            'rows' => $rows,
            'base' => round(array_sum(array_column($rows, 'base')), 2) + 0.0,
            'vat' => round(array_sum(array_column($rows, 'vat')), 2) + 0.0,
            'total' => round(array_sum(array_column($rows, 'total')), 2) + 0.0,
            'missing_rate' => $missing,
        ];
    }

    /**
     * @param  array<string, array{key: string, label: string, documents: array<string, true>, quantity: float|null, base: float, vat: float}>  $rows
     */
    private function add(array &$rows, string $key, string $label, string $document, float $base, float $vat, ?float $quantity): void
    {
        $rows[$key] ??= ['key' => $key, 'label' => $label, 'documents' => [], 'quantity' => $quantity === null ? null : 0.0, 'base' => 0.0, 'vat' => 0.0];
        $rows[$key]['documents'][$document] = true;
        $rows[$key]['base'] += $base;
        $rows[$key]['vat'] += $vat;

        if ($quantity !== null) {
            $rows[$key]['quantity'] = ($rows[$key]['quantity'] ?? 0.0) + $quantity;
        }
    }
}
