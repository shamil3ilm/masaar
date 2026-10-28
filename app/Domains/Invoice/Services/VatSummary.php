<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Support\BillingReferences;
use App\Domains\Invoice\Support\DisbursementLines;
use App\Domains\Organization\Services\BranchScope;
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
 * Sales only: purchases come from Books (PurchaseLedger), and the country's
 * VatReturn drafts the whole return from both.
 *
 * Each document's rows are in whole halalas, converted once per document
 * (converted()), so a box is the sum of what its documents state in riyals.
 *
 * Of the branches the report shows (BranchScope): every one the person may
 * see, the one chosen in the switcher, or one at a time for a breakdown.
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

    public function __construct(
        private readonly BranchScope $branches,
    ) {}

    /**
     * @return array{
     *     boxes: array<int, array{label: string, base: float, vat: float, adjustment_base: float, adjustment_vat: float}>,
     *     out_of_scope: float,
     *     disbursements: float,
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
        $disbursements = 0.0;
        $documents = 0;
        $creditNotes = 0;
        $adjustments = 0;
        $converted = 0;
        $missingRate = [];

        foreach ($this->contributions($organizationId, $from, $to) as $row) {
            if ($row['rate'] === null) {
                $missingRate[] = (string) $row['invoice']->invoice_number;

                continue;
            }

            if ($row['first']) {
                // Out of scope like the rest, and named apart: costs paid in
                // the buyer's name (DisbursementLines).
                $disbursements += $row['sign'] * (float) DisbursementLines::total($row['invoice']->lines) * $row['rate'];
                $documents++;
                $converted += $row['rate'] !== 1.0 ? 1 : 0;
                $creditNotes += $row['sign'] < 0 ? 1 : 0;
                $adjustments += $row['adjusts'] ? 1 : 0;
            }

            if ($row['box'] === null) {
                $outOfScope += $row['base'];

                continue;
            }

            [$baseKey, $vatKey] = $row['adjusts'] ? ['adjustment_base', 'adjustment_vat'] : ['base', 'vat'];
            $boxes[$row['box']][$baseKey] += $row['base'];
            $boxes[$row['box']][$vatKey] += $row['vat'];
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
            'disbursements' => round($disbursements, 2),
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
     * What each document adds to the return, one category at a time, in
     * riyals and signed: the rows for() sums, so a box can be traced back to
     * the documents behind it. Box is null for an out-of-scope category. A
     * document without its rate yields one row with a null rate and nothing
     * to add, so it can be named. First marks a document's first row.
     *
     * Refused gives the same rows for the documents ZATCA rejected, which
     * the return leaves out, so they can be shown apart.
     *
     * @return \Generator<int, array{invoice: Invoice, box: int|null, adjusts: bool, sign: int, rate: float|null, first: bool, base: float, vat: float}>
     */
    public function contributions(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, bool $refused = false): \Generator
    {
        $earlier = $this->earlierOriginals($organizationId, $from, $to);

        foreach ($this->documents($organizationId, $from, $to, $refused) as $invoice) {
            $sign = $invoice->document_type === DocumentType::CreditNote ? -1 : 1;
            // A credit note only: a debit note after the original's return
            // goes in the amount of the period it is raised in (§7.1.1).
            $adjusts = $sign < 0 && isset($earlier[(string) $invoice->billing_ref]);

            // The return is in riyals. Converting at 1 would misstate it, so
            // a document without its rate is left out and named instead.
            $rate = $this->rate($invoice);

            if ($rate === null) {
                yield ['invoice' => $invoice, 'box' => null, 'adjusts' => $adjusts, 'sign' => $sign, 'rate' => null, 'first' => true, 'base' => 0.0, 'vat' => 0.0];

                continue;
            }

            $first = true;

            foreach ($this->converted($this->categories($invoice), $rate) as $category) {
                yield [
                    'invoice' => $invoice,
                    'box' => $category['code'] === 'O' ? null : $this->boxFor($category['code'], $category['exempt']),
                    'adjusts' => $adjusts,
                    'sign' => $sign,
                    'rate' => $rate,
                    'first' => $first,
                    'base' => $sign * $category['base'] + 0.0,
                    'vat' => $sign * $category['tax'] + 0.0,
                ];
                $first = false;
            }

            // A document with nothing on it still counts as one.
            if ($first) {
                yield ['invoice' => $invoice, 'box' => null, 'adjusts' => $adjusts, 'sign' => $sign, 'rate' => $rate, 'first' => true, 'base' => 0.0, 'vat' => 0.0];
            }
        }
    }

    /**
     * The documents that count for the period, oldest first, with lines.
     *
     * @return LazyCollection<int, Invoice>
     */
    public function documents(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, bool $refused = false): LazyCollection
    {
        return Invoice::withoutTenantScope(fn () => $this->query($organizationId, $from, $to, refused: $refused)
            ->with(['lines', 'charges', 'prepayments'])
            ->orderBy('issue_date')
            ->orderBy('icv')
            ->lazy(200));
    }

    /**
     * Documents of any country issued in the period, oldest first, with
     * lines: the same tax point as documents(), without asking for a ZATCA
     * hash, which a UAE invoice never has.
     *
     * @return LazyCollection<int, Invoice>
     */
    public function issued(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): LazyCollection
    {
        return Invoice::withoutTenantScope(fn () => $this->query($organizationId, $from, $to, sealed: false)
            ->with(['lines', 'charges', 'prepayments'])
            ->orderBy('issue_date')
            ->orderBy('issued_at')
            ->orderBy('invoice_number')
            ->lazy(200));
    }

    /**
     * The document's rate to riyals, or null for a foreign currency without
     * one: the tax must be stated in riyals ([GL] §4.2.2). Another country's
     * tax currency is named, as the UAE's dirhams are.
     */
    public function rate(Invoice $invoice, string $taxCurrency = 'SAR'): ?float
    {
        if (strtoupper((string) ($invoice->currency ?: $taxCurrency)) === $taxCurrency) {
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
     * Sealed asks for the ZATCA hash an issued Saudi document has; unsealed
     * for any document past draft. Refused asks for only what ZATCA rejected,
     * which every other reading leaves out.
     *
     * @return Builder<Invoice>
     */
    private function query(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, bool $sealed = true, bool $refused = false): Builder
    {
        $start = $from->toDateString();
        $until = $to->addDay()->toDateString();

        return $this->branches->applyVisible(Invoice::query())
            ->where('org_id', $organizationId)
            ->when($sealed,
                fn (Builder $query) => $query->whereNotNull('hash'),
                fn (Builder $query) => $query->where('status', '!=', InvoiceStatus::Draft))
            ->where('status', $refused ? '=' : '!=', InvoiceStatus::Rejected)
            ->where('document_type', '!=', DocumentType::Proforma)
            // A tax invoice for a payment after bad-debt relief: its tax is
            // the relief's reversal, in the payment's period (SalesBadDebts).
            ->whereNotIn('id', InvoiceAdjustments::recoveryInvoices())
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
     * The billing references, among the period's credit notes', that name an
     * original whose own tax point is before the period - a return already
     * filed. A note may name its original by number or by row id, and may
     * name several (BillingReferences): one on several is reported against
     * its earliest, as VatPeriodTracker validates it, so it adjusts when any
     * of them is earlier. A note whose originals are not found here nets
     * against the period as before.
     *
     * @return array<string, true>
     */
    private function earlierOriginals(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->toDateString();

        return Invoice::withoutTenantScope(function () use ($organizationId, $from, $to, $start): array {
            $named = [];

            foreach ($this->query($organizationId, $from, $to)->where('document_type', DocumentType::CreditNote)
                ->whereNotNull('billing_ref')->distinct()->pluck('billing_ref') as $billingRef) {
                $named[(string) $billingRef] = BillingReferences::of((string) $billingRef);
            }

            $references = array_values(array_unique(array_merge([], ...array_values($named))));

            if ($references === []) {
                return [];
            }

            $earlier = [];

            foreach (Invoice::query()
                ->where('org_id', $organizationId)
                ->whereNotNull('hash')
                ->where('status', '!=', InvoiceStatus::Rejected)
                ->whereIn('document_type', [DocumentType::Invoice, DocumentType::Prepayment])
                ->where(fn (Builder $query) => $query->whereIn('invoice_number', $references)->orWhereIn('id', $references))
                ->where(fn (Builder $query) => $query
                    ->where('issue_date', '<', $start)
                    ->orWhere(fn (Builder $supply) => $supply
                        ->whereNull('supply_end_date')
                        ->where('supply_date', '<', $start)))
                ->get(['id', 'invoice_number']) as $original) {
                $earlier[(string) $original->invoice_number] = true;
                $earlier[(string) $original->id] = true;
            }

            return array_map(fn (): bool => true, array_filter($named,
                fn (array $references): bool => array_intersect_key(array_flip($references), $earlier) !== []));
        });
    }

    /**
     * Each category on the document after its discount, with the exemption
     * code that decides its box.
     *
     * @return list<array{code: string, exempt: string|null, base: float, tax: float}>
     */
    public function categories(Invoice $invoice): array
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

        // Document charges add to the base of the category they are made
        // at, which may be one no line has.
        $charges = [];

        foreach ($invoice->charges as $charge) {
            $code = (string) ($charge->tax_category ?: 'S');
            $key = $code.'|'.(string) (float) $charge->tax_rate.'|'.$charge->exempt_code;
            $charges[$key] = ($charges[$key] ?? 0.0) + (float) $charge->net_amount;
            $rates[$key] = (float) $charge->tax_rate;
            $codes[$key] ??= ['code' => $code, 'exempt' => $charge->exempt_code];
        }

        $categories = [];

        foreach (InvoiceTotals::categories($nets, $rates, (float) $invoice->discount_amount, (float) $invoice->tax_amount, $charges) as $key => $category) {
            $categories[] = [...$codes[$key], 'base' => $category['base'], 'tax' => $category['tax']];
        }

        // A final invoice states the whole supply and its VAT; what its
        // prepayment invoices already declared is taken off, so the advance
        // is not counted twice.
        foreach ($invoice->prepayments as $prepayment) {
            $categories[] = [
                'code' => (string) $prepayment->tax_category,
                'exempt' => $prepayment->exempt_code,
                'base' => -(float) $prepayment->taxable_amount,
                'tax' => -(float) $prepayment->tax_amount,
            ];
        }

        return $categories;
    }

    /**
     * The categories in riyals, in whole halalas, adding up to the document
     * converted once: its VAT as it states it in riyals (BT-111, the total
     * VAT times the rate, rounded - InvoiceXmlData::convertToSar) and its
     * base the same way. Converting each category on its own could leave a
     * multi-category foreign document a halala off its own figure; the
     * difference goes to the largest category.
     *
     * @param  list<array{code: string, exempt: string|null, base: float, tax: float}>  $categories
     * @return list<array{code: string, exempt: string|null, base: float, tax: float}>
     */
    private function converted(array $categories, float $rate): array
    {
        foreach (['base', 'tax'] as $key) {
            $amounts = array_column($categories, $key);

            if ($amounts === []) {
                continue;
            }

            $whole = round(array_sum($amounts) * $rate, 2);
            $largest = array_search(max(array_map('abs', $amounts)), array_map('abs', $amounts), true);

            foreach ($categories as $index => $category) {
                $categories[$index][$key] = round($category[$key] * $rate, 2);
            }

            if ($largest !== false) {
                $categories[$largest][$key] = round($categories[$largest][$key] + $whole - array_sum(array_column($categories, $key)), 2);
            }
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
