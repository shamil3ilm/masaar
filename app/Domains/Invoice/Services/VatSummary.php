<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * An organization's sales for a period, grouped as the VAT return asks.
 *
 * Only documents that count: issued (they have a hash), not refused by ZATCA,
 * and not a proforma. Credit notes take away from the box they belong to.
 * Each document's own split of its discount across its categories is reused
 * - InvoiceTotals::categories, with the stored VAT - so the boxes add up to
 * the invoices exactly rather than to a recomputation of them.
 *
 * Sales only. The return also asks for purchases, which the platform does
 * not see.
 */
class VatSummary
{
    /** The return's sales boxes, and what lands in each. */
    public const BOXES = [
        1 => 'Standard rated sales',
        2 => 'Sales to citizens (private healthcare, private education)',
        3 => 'Zero rated domestic sales',
        4 => 'Exports',
        5 => 'Exempt sales',
    ];

    /** Zero-rated supplies that are exports for the return. */
    private const EXPORTS = ['VATEX-SA-34-3', 'VATEX-SA-34-4', 'VATEX-SA-34-5'];

    /** Zero-rated supplies to citizens, box 2. */
    private const CITIZENS = ['VATEX-SA-EDU', 'VATEX-SA-HEA'];

    /**
     * @return array{
     *     boxes: array<int, array{label: string, base: float, vat: float}>,
     *     out_of_scope: float,
     *     total_base: float,
     *     total_vat: float,
     *     documents: int,
     *     credit_notes: int,
     *     converted: int
     * }
     */
    public function for(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $boxes = [];

        foreach (self::BOXES as $box => $label) {
            $boxes[$box] = ['label' => $label, 'base' => 0.0, 'vat' => 0.0];
        }

        $outOfScope = 0.0;
        $documents = 0;
        $creditNotes = 0;
        $converted = 0;

        foreach ($this->documents($organizationId, $from, $to) as $invoice) {
            $documents++;
            $sign = $invoice->document_type === DocumentType::CreditNote ? -1 : 1;
            $creditNotes += $sign < 0 ? 1 : 0;

            // The return is in riyals. A foreign-currency invoice carries the
            // rate it was issued at.
            $rate = strtoupper((string) $invoice->currency) === 'SAR' ? 1.0 : (float) ($invoice->exchange_rate ?: 1);
            $converted += $rate !== 1.0 ? 1 : 0;

            foreach ($this->categories($invoice) as $category) {
                $base = $sign * $category['base'] * $rate;
                $vat = $sign * $category['tax'] * $rate;

                if ($category['code'] === 'O') {
                    $outOfScope += $base;

                    continue;
                }

                $box = $this->boxFor($category['code'], $category['exempt']);
                $boxes[$box]['base'] += $base;
                $boxes[$box]['vat'] += $vat;
            }
        }

        foreach ($boxes as $box => $values) {
            $boxes[$box]['base'] = round($values['base'], 2);
            $boxes[$box]['vat'] = round($values['vat'], 2);
        }

        return [
            'boxes' => $boxes,
            'out_of_scope' => round($outOfScope, 2),
            'total_base' => round(array_sum(array_column($boxes, 'base')), 2),
            'total_vat' => round(array_sum(array_column($boxes, 'vat')), 2),
            'documents' => $documents,
            'credit_notes' => $creditNotes,
            'converted' => $converted,
        ];
    }

    /**
     * The documents that count for the period, oldest first, with lines.
     *
     * @return LazyCollection<int, Invoice>
     */
    public function documents(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): LazyCollection
    {
        return Invoice::withoutTenantScope(fn () => $this->query($organizationId, $from, $to)
            ->with('lines')
            ->orderBy('issue_date')
            ->orderBy('icv')
            ->lazy(200));
    }

    /**
     * @return Builder<Invoice>
     */
    private function query(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Invoice::query()
            ->where('org_id', $organizationId)
            ->whereNotNull('hash')
            ->where('status', '!=', InvoiceStatus::Rejected)
            ->where('document_type', '!=', DocumentType::Proforma)
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * Each category on the document after its discount, with the exemption
     * code that decides its box.
     *
     * @return list<array{code: string, exempt: string|null, base: float, tax: float}>
     */
    private function categories(Invoice $invoice): array
    {
        $nets = [];
        $rates = [];
        $codes = [];

        foreach ($invoice->lines as $line) {
            $code = $line->tax_category instanceof \BackedEnum ? $line->tax_category->value : (string) ($line->tax_category ?: 'S');
            $key = $code.'|'.(string) (float) $line->tax_rate.'|'.$line->exempt_code;
            $net = $line->net_amount !== null ? (float) $line->net_amount : (float) $line->line_total - (float) $line->tax_amount;

            $nets[$key] = ($nets[$key] ?? 0.0) + $net;
            $rates[$key] = (float) $line->tax_rate;
            $codes[$key] = ['code' => $code, 'exempt' => $line->exempt_code];
        }

        $categories = [];

        foreach (InvoiceTotals::categories($nets, $rates, (float) $invoice->discount_amount, (float) $invoice->tax_amount) as $key => $category) {
            $categories[] = [...$codes[$key], 'base' => $category['base'], 'tax' => $category['tax']];
        }

        return $categories;
    }

    private function boxFor(string $code, ?string $exempt): int
    {
        return match (true) {
            $code === 'E' => 5,
            $code === 'Z' && in_array($exempt, self::EXPORTS, true) => 4,
            $code === 'Z' && in_array($exempt, self::CITIZENS, true) => 2,
            $code === 'Z' => 3,
            default => 1,
        };
    }
}
