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
 * An invoice belongs to the period of its tax point: the earlier of its
 * supply date and its issue date (Guideline for Tax Invoicing and Records
 * §4.5.1). A credit note against an invoice from an earlier period goes in
 * the box's Adjustments, not its amount (§7.1.1).
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

    /**
     * Zero-rated supplies that are exports for the return: export of goods
     * and export of services (XML Implementation Standard §11.2.4).
     *
     * International transport (VATEX-SA-34-1 to 34-5), medicines (35),
     * qualifying metals (36) and military goods (MLTRY) are zero-rated
     * supplies the standard lists apart from the two exports, and neither it
     * nor the Guideline puts them under Exports, so they are box 3.
     */
    private const EXPORTS = ['VATEX-SA-32', 'VATEX-SA-33'];

    /** Zero-rated supplies to citizens, box 2. */
    private const CITIZENS = ['VATEX-SA-EDU', 'VATEX-SA-HEA'];

    private const NOTES = [DocumentType::CreditNote, DocumentType::DebitNote];

    /**
     * @return array{
     *     boxes: array<int, array{label: string, base: float, vat: float, adjustment_base: float, adjustment_vat: float}>,
     *     out_of_scope: float,
     *     total_base: float,
     *     total_vat: float,
     *     total_adjustment_base: float,
     *     total_adjustment_vat: float,
     *     net_base: float,
     *     net_vat: float,
     *     documents: int,
     *     credit_notes: int,
     *     adjustments: int,
     *     converted: int,
     *     missing_rate: list<string>
     * }
     */
    public function for(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $boxes = [];

        foreach (self::BOXES as $box => $label) {
            $boxes[$box] = ['label' => $label, 'base' => 0.0, 'vat' => 0.0, 'adjustment_base' => 0.0, 'adjustment_vat' => 0.0];
        }

        $outOfScope = 0.0;
        $documents = 0;
        $creditNotes = 0;
        $adjustments = 0;
        $converted = 0;
        $missingRate = [];
        $earlier = $this->earlierOriginals($organizationId, $from, $to);

        foreach ($this->documents($organizationId, $from, $to) as $invoice) {
            // The return is in riyals. Converting at 1 would misstate it, so
            // a document without its rate is left out and named instead.
            $rate = $this->rate($invoice);

            if ($rate === null) {
                $missingRate[] = (string) $invoice->invoice_number;

                continue;
            }

            $documents++;
            $converted += $rate !== 1.0 ? 1 : 0;
            $sign = $invoice->document_type === DocumentType::CreditNote ? -1 : 1;
            $creditNotes += $sign < 0 ? 1 : 0;

            // A credit note only: a debit note after the original's return
            // goes in the amount of the period it is raised in (§7.1.1).
            $adjusts = $sign < 0 && isset($earlier[(string) $invoice->billing_ref]);
            $adjustments += $adjusts ? 1 : 0;
            [$baseKey, $vatKey] = $adjusts ? ['adjustment_base', 'adjustment_vat'] : ['base', 'vat'];

            foreach ($this->categories($invoice) as $category) {
                $base = $sign * $category['base'] * $rate;
                $vat = $sign * $category['tax'] * $rate;

                if ($category['code'] === 'O') {
                    $outOfScope += $base;

                    continue;
                }

                $box = $this->boxFor($category['code'], $category['exempt']);
                $boxes[$box][$baseKey] += $base;
                $boxes[$box][$vatKey] += $vat;
            }
        }

        foreach ($boxes as $box => $values) {
            foreach (['base', 'vat', 'adjustment_base', 'adjustment_vat'] as $key) {
                $boxes[$box][$key] = round($values[$key], 2);
            }
        }

        $total = fn (string $key): float => round(array_sum(array_column($boxes, $key)), 2);

        return [
            'boxes' => $boxes,
            'out_of_scope' => round($outOfScope, 2),
            'total_base' => $total('base'),
            'total_vat' => $total('vat'),
            'total_adjustment_base' => $total('adjustment_base'),
            'total_adjustment_vat' => $total('adjustment_vat'),
            'net_base' => round($total('base') + $total('adjustment_base'), 2),
            'net_vat' => round($total('vat') + $total('adjustment_vat'), 2),
            'documents' => $documents,
            'credit_notes' => $creditNotes,
            'adjustments' => $adjustments,
            'converted' => $converted,
            'missing_rate' => $missingRate,
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
     * The document's rate to riyals, or null for a foreign currency without
     * one: the tax must be stated in riyals ([GL] §4.2.2).
     */
    public function rate(Invoice $invoice): ?float
    {
        if (strtoupper((string) ($invoice->currency ?: 'SAR')) === 'SAR') {
            return 1.0;
        }

        $rate = (float) $invoice->exchange_rate;

        return $rate > 0 ? $rate : null;
    }

    /**
     * The documents whose tax point falls in the period.
     *
     * The tax point is the issue date for a note (its supply is the
     * original's), for a supply over a period (continuous supplies, [GL]
     * §4.5.2), and for an invoice issued no later than its supply; otherwise
     * it is the supply date. Two plain ranges rather than a computed date, so
     * it reads the same on SQLite and MySQL. Half-open, so the last day
     * counts whether the column holds a date or a datetime.
     *
     * @return Builder<Invoice>
     */
    private function query(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $start = $from->toDateString();
        $until = $to->addDay()->toDateString();

        return Invoice::query()
            ->where('org_id', $organizationId)
            ->whereNotNull('hash')
            ->where('status', '!=', InvoiceStatus::Rejected)
            ->where('document_type', '!=', DocumentType::Proforma)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $onIssue) => $onIssue
                    ->where(fn (Builder $kind) => $kind
                        ->whereIn('document_type', self::NOTES)
                        ->orWhereNull('supply_date')
                        ->orWhereNotNull('supply_end_date')
                        ->orWhereColumn('supply_date', '>=', 'issue_date'))
                    ->where('issue_date', '>=', $start)
                    ->where('issue_date', '<', $until))
                ->orWhere(fn (Builder $onSupply) => $onSupply
                    ->whereNotIn('document_type', self::NOTES)
                    ->whereNull('supply_end_date')
                    ->whereColumn('supply_date', '<', 'issue_date')
                    ->where('supply_date', '>=', $start)
                    ->where('supply_date', '<', $until)));
    }

    /**
     * The invoice numbers, among those the period's credit notes adjust,
     * whose own tax point is before the period - a return already filed. A
     * note whose original is not found here nets against the period as
     * before.
     *
     * @return array<string, true>
     */
    private function earlierOriginals(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->toDateString();

        return Invoice::withoutTenantScope(fn () => Invoice::query()
            ->where('org_id', $organizationId)
            ->whereNotNull('hash')
            ->where('status', '!=', InvoiceStatus::Rejected)
            ->whereIn('document_type', [DocumentType::Invoice, DocumentType::Prepayment])
            ->whereIn('invoice_number', $this->query($organizationId, $from, $to)
                ->where('document_type', DocumentType::CreditNote)
                ->select('billing_ref'))
            ->where(fn (Builder $query) => $query
                ->where('issue_date', '<', $start)
                ->orWhere(fn (Builder $supply) => $supply
                    ->whereNull('supply_end_date')
                    ->where('supply_date', '<', $start)))
            ->pluck('invoice_number')
            ->mapWithKeys(fn ($number): array => [(string) $number => true])
            ->all());
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
