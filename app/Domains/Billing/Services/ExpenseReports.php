<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentAllocation;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Refund;
use App\Domains\Billing\Models\SupplierCreditNote;
use App\Domains\Billing\Models\SupplierPayment;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Books\Services\SalesBadDebts;
use App\Domains\Compliance\Engine\DTOs\VatReturnEntry;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Services\VatSummary;
use App\Domains\Organization\Services\TenantResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Admin › Finance › Expense reports: what the platform spent by category,
 * supplier, month and cost centre; the VAT on it as the return takes it;
 * and a profit and loss outline - its revenue less its costs, by month.
 *
 * Every figure is in the tax currency, at each expense's recorded rate, so
 * costs billed in different currencies add up. An expense counts from its
 * date once recorded; drafts and void ones count nowhere.
 *
 * What an expense costs the platform is what it cannot get back: its
 * amount before VAT, plus any VAT it pays or self-assesses and does not
 * deduct (net + VAT + reverse charge - recoverable).
 */
class ExpenseReports
{
    public const REPORTS = ['category', 'supplier', 'month', 'cost_centre', 'vat', 'pnl', 'payables', 'deferral'];

    public const GROUPS = ['category', 'supplier', 'month', 'cost_centre'];

    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly FinanceReports $finance,
        private readonly Payables $payables,
        private readonly PlatformInvoices $platform,
        private readonly ExchangeRates $rates,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly TenantResolver $tenant,
        private readonly SalesBadDebts $badDebts,
        private readonly VatSummary $vatSummary,
    ) {}

    public function taxCurrency(): string
    {
        return $this->platform->taxCurrency();
    }

    /**
     * Spent in the period, one row per category, supplier, month or cost
     * centre, the largest first (by month, in order).
     *
     * @return list<array{key: string, name: string, count: int, net: string, vat: string, recoverable: string, cost: string}>
     */
    public function grouped(string $by, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $names = match ($by) {
            'category' => $this->settings->expenseCategories(),
            'cost_centre' => $this->settings->costCentres(),
            default => [],
        };
        $rows = [];

        foreach ($this->counted($from, $to) as $expense) {
            $key = match ($by) {
                'category' => $expense->category,
                'supplier' => (string) $expense->supplier_id,
                'month' => $expense->expense_date->format('Y-m'),
                default => (string) $expense->cost_centre,
            };
            $name = match ($by) {
                'supplier' => (string) $expense->supplier?->name,
                'month' => $key,
                default => $key === '' ? __('None') : ($names[$key] ?? $key),
            };

            $rows[$key] ??= ['key' => $key, 'name' => $name, 'count' => 0, 'net' => 0.0, 'vat' => 0.0, 'recoverable' => 0.0, 'cost' => 0.0];
            $rows[$key]['count'] += $expense->getAttribute('credit_note') === true ? 0 : 1;
            $rows[$key]['net'] += (float) $expense->net_tax;
            $rows[$key]['vat'] += (float) $expense->vat_tax + (float) $expense->self_assessed_vat_tax;
            $rows[$key]['recoverable'] += (float) $expense->recoverable_vat_tax;
            $rows[$key]['cost'] += $this->cost($expense);
        }

        $by === 'month' ? ksort($rows) : uasort($rows, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return array_values(array_map(fn (array $row): array => [...$row, ...$this->money($row, ['net', 'vat', 'recoverable', 'cost'])], $rows));
    }

    /**
     * The VAT return's figures for the period from the platform's own side:
     * output VAT on what it sold (FinanceReports::vat), and from its
     * purchases the input VAT it deducts and the reverse charge it
     * assesses - as output VAT and as input VAT, both on the same return
     * (VAT Implementing Regulations Art 47(1)).
     *
     * @return array{summary: array<string, string>, expenses: list<array<string, string>>, tax_currency: string}
     */
    public function vat(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sales = $this->sales($from, $to);
        $sums = ['purchases_net' => 0.0, 'input_charged' => 0.0, 'input_recoverable' => 0.0, 'input_not_recoverable' => 0.0, 'reverse_charge_base' => 0.0, 'reverse_charge_output' => 0.0, 'reverse_charge_input' => 0.0];
        $lines = [];

        foreach ($this->counted($from, $to) as $expense) {
            $reverse = $expense->vat_treatment === VatTreatment::ReverseCharge;
            $sums['purchases_net'] += (float) $expense->net_tax;

            if ($reverse) {
                $sums['reverse_charge_base'] += (float) $expense->net_tax;
                $sums['reverse_charge_output'] += (float) $expense->self_assessed_vat_tax;
                $sums['reverse_charge_input'] += (float) $expense->recoverable_vat_tax;
            } else {
                $sums['input_charged'] += (float) $expense->vat_tax;
                $sums['input_recoverable'] += (float) $expense->recoverable_vat_tax;
                $sums['input_not_recoverable'] += (float) $expense->vat_tax - (float) $expense->recoverable_vat_tax;
            }

            $lines[] = [
                'date' => $expense->expense_date->toDateString(),
                'supplier' => (string) $expense->supplier?->name,
                'number' => (string) $expense->supplier_invoice_number,
                'treatment' => $expense->vat_treatment->label(),
                'net' => (string) $expense->net_tax,
                'vat' => (string) $expense->vat_tax,
                'self_assessed' => (string) $expense->self_assessed_vat_tax,
                'recoverable' => (string) $expense->recoverable_vat_tax,
            ];
        }

        $output = (float) $sales['vat'] + $sums['reverse_charge_output'];
        $input = $sums['input_recoverable'] + $sums['reverse_charge_input'];
        // Of the output VAT, what bad-debt relief took back (below zero) or
        // gave back; and the VAT on what was written off in the period,
        // which relief takes back once the country's conditions are met.
        $relief = -array_sum(array_column($this->reliefs($from, $to), 1));
        $writtenOffVat = 0.0;

        foreach (Receivable::query()->withoutGlobalScopes()->where('amount_written_off', '>', 0)
            ->where('written_off_at', '>=', $from->toDateString())->where('written_off_at', '<', $to->addDay()->toDateString())->get() as $receivable) {
            $rate = $this->rateFor((string) $receivable->currency, $receivable->exchange_rate, null);
            $writtenOffVat += $rate === null ? 0.0 : $this->writtenOffSplit($receivable, $rate)[1];
        }

        return [
            'summary' => [
                'sales_net' => $sales['net'],
                'sales_output' => $sales['vat'],
                'bad_debt_relief' => Receivable::money($relief),
                'written_off_vat' => Receivable::money($writtenOffVat),
                ...$this->money($sums, array_keys($sums)),
                'output_total' => Receivable::money($output),
                'input_total' => Receivable::money($input),
                'net_payable' => Receivable::money($output - $input),
            ],
            'expenses' => $lines,
            'sales_boxes' => $sales['boxes'],
            'tax_currency' => $this->taxCurrency(),
        ];
    }

    /**
     * The platform's sales as its own country's VAT return groups them - the
     * same return the customers' Books draft (VatReturn) - so they are by
     * box and by tax point, and the billing company may be in either
     * country. Only the sales boxes: the platform's purchases are its
     * expenses, which this report adds itself.
     *
     * @return array{net: string, vat: string, boxes: list<array{box: string, label: string, amount: string, adjustment: string, vat: string}>}
     */
    private function sales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $seller = $this->platform->seller();

        if ($seller === null) {
            return ['net' => Receivable::money(0), 'vat' => Receivable::money(0), 'boxes' => []];
        }

        // As the billing company: the console has no tenant of its own, and
        // the return reads the company's documents through its tenant scope.
        $draft = $this->tenant->runAs((string) $seller->id, fn () => $this->jurisdictions->forRules($seller)->vatReturn()->draft((string) $seller->id, $from, $to));
        $boxes = [];
        $net = 0.0;
        $vat = 0.0;

        foreach ($draft->sections as $section) {
            foreach ($section as $box) {
                $sold = array_values(array_filter($box->entries, fn ($entry): bool => $entry->kind === VatReturnEntry::SALE));

                if ($sold === []) {
                    continue;
                }

                $sum = fn (string $column): float => array_sum(array_map(fn ($entry): float => $entry->{$column}, $sold));
                $net += $sum('amount') + $sum('adjustment');
                $vat += $sum('vat');
                $boxes[] = ['box' => $box->key, 'label' => __($box->label), 'amount' => Receivable::money($sum('amount')), 'adjustment' => Receivable::money($sum('adjustment')), 'vat' => Receivable::money($sum('vat'))];
            }
        }

        return ['net' => Receivable::money($net), 'vat' => Receivable::money($vat), 'boxes' => $boxes];
    }

    /** The profit and loss columns, in order; profit is worked out from them. */
    public const PNL_COLUMNS = ['revenue', 'costs', 'exchange', 'customer_exchange', 'fees', 'bad_debts', 'bad_debt_vat', 'vat_relief', 'payables_written_off', 'goodwill'];

    /** What each column does to profit: income adds, a cost takes away. */
    private const PNL_SIGNS = ['revenue' => 1, 'costs' => -1, 'exchange' => 1, 'customer_exchange' => 1, 'fees' => -1, 'bad_debts' => -1, 'bad_debt_vat' => -1, 'vat_relief' => 1, 'payables_written_off' => 1, 'goodwill' => -1];

    /** @return array<string, string> column => heading */
    public static function pnlHeadings(): array
    {
        return [
            'revenue' => __('Revenue'),
            'costs' => __('Costs'),
            'exchange' => __('Exchange differences on supplier payments'),
            'customer_exchange' => __('Exchange differences on customer receipts'),
            'fees' => __('Bank and card fees'),
            'bad_debts' => __('Bad debts written off'),
            'bad_debt_vat' => __('VAT on bad debts written off'),
            'vat_relief' => __('VAT taken back as bad-debt relief'),
            'payables_written_off' => __('Supplier bills written off'),
            'goodwill' => __('Goodwill credits used'),
        ];
    }

    /**
     * Profit and loss, an outline by month, every line in the tax currency
     * in the month it happened:
     *
     *  - revenue: the platform's own documents before VAT, credit notes
     *    taking away (FinanceReports::vat);
     *  - costs: what its expenses cost it;
     *  - exchange: differences realised paying suppliers (a gain adds);
     *  - customer_exchange: differences realised on customers' payments
     *    against their invoices' rates (payment_allocations.fx_difference,
     *    on the day set; a reversal takes its own back);
     *  - fees: what the bank or the card gateway kept of payments received
     *    (a reconciliation shortfall taken as a fee, or the gateway's), a
     *    reversed payment's fee taken back in the month of its reversal -
     *    or, as Finance › Settings chooses, the bills in a fee category
     *    instead, never both;
     *  - bad_debts: invoices written off, their amount before VAT, on the
     *    day written off;
     *  - bad_debt_vat: the VAT on what was written off - declared and
     *    never collected - a cost until bad-debt relief takes it back;
     *  - vat_relief: output VAT taken back under bad-debt relief on the
     *    billing company's own invoices (SalesBadDebts), in the period it
     *    counts in, and given back below zero when paid after all;
     *  - payables_written_off: what suppliers waived, a gain;
     *  - goodwill: adjustment credits (BillingCredit::ADJUSTMENT) taken by
     *    invoices, money never received for them.
     *
     * None counts what another does: credit notes are in revenue, so their
     * leftover kept as credit is not goodwill; overpayment credit is money
     * received, not a cost. Anything in another currency with no rate on
     * record or in the table is listed under unconverted, never taken at 1.
     *
     * @return array{months: list<array<string, string>>, totals: array<string, string>, unconverted: list<string>}
     */
    public function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $blank = fn (string $key): array => ['month' => $key, ...array_fill_keys(self::PNL_COLUMNS, 0.0)];
        $months = [];
        $unconverted = [];

        for ($month = $from->startOfMonth(); $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = $blank($month->format('Y-m'));
        }

        foreach ($this->finance->vat($from, $to)['documents'] as $document) {
            $key = substr($document['date'], 0, 7);
            $months[$key] ??= $blank($key);
            $months[$key]['revenue'] += (float) $document['net_tax'];
        }

        // Bank and card fees from one source only (FinanceSettings::feeSource):
        // an expense in a fee category is never also a cost.
        $feeCategories = $this->settings->feeCategories();
        $feesFromExpenses = $this->settings->feeSource() === FinanceSettings::FEES_FROM_EXPENSES;

        foreach ($this->counted($from, $to) as $expense) {
            $fee = in_array($expense->category, $feeCategories, true);

            if ($fee && ! $feesFromExpenses) {
                continue;
            }

            $key = $expense->expense_date->format('Y-m');
            $months[$key] ??= $blank($key);
            $months[$key][$fee ? 'fees' : 'costs'] += $this->cost($expense);
        }

        foreach ($this->payments($from, $to) as $payment) {
            $key = $payment->paid_on->format('Y-m');
            $months[$key] ??= $blank($key);
            $months[$key]['exchange'] += (float) $payment->exchange_difference;
        }

        foreach ($this->customerLines($from, $to, $unconverted, ! $feesFromExpenses) as [$date, $column, $amount]) {
            $key = substr($date, 0, 7);
            $months[$key] ??= $blank($key);
            $months[$key][$column] += $amount;
        }

        ksort($months);
        $totals = array_fill_keys(self::PNL_COLUMNS, 0.0);

        foreach ($months as $row) {
            foreach (self::PNL_COLUMNS as $column) {
                $totals[$column] += $row[$column];
            }
        }

        $finish = fn (array $row): array => [
            ...$this->money($row, self::PNL_COLUMNS),
            'profit' => Receivable::money(array_sum(array_map(fn (string $column): float => self::PNL_SIGNS[$column] * $row[$column], self::PNL_COLUMNS))),
        ];

        return [
            'months' => array_values(array_map(fn (array $row): array => ['month' => $row['month'], ...$finish($row)], $months)),
            'totals' => $finish($totals),
            'unconverted' => $unconverted,
        ];
    }

    /**
     * The lines the customers' side adds, each as [date, column, amount in
     * the tax currency]: fees, exchange differences, bad debts, goodwill.
     *
     * @param  list<string>  $unconverted  what could not be put in the tax currency, by reference
     * @return list<array{0: string, 1: string, 2: float}>
     */
    private function customerLines(CarbonImmutable $from, CarbonImmutable $to, array &$unconverted, bool $paymentFees): array
    {
        $start = $from->toDateString();
        $until = $to->addDay()->toDateString();
        $lines = [];

        // A fee in the month of its payment; a reversed payment's fee taken
        // back in the month of its reversal (FinanceReports::payments).
        $payments = ! $paymentFees ? collect() : Payment::query()->withoutGlobalScopes()
            ->where('fee_amount', '>', 0)
            ->where(fn ($query) => $query
                ->where(fn ($paid) => $paid->where('paid_at', '>=', $start)->where('paid_at', '<', $until))
                ->orWhere(fn ($reversed) => $reversed->where('reversed_at', '>=', $start)->where('reversed_at', '<', $until)))
            ->get();

        foreach ($payments as $payment) {
            $rate = $this->rateFor((string) $payment->currency, $payment->exchange_rate, CarbonImmutable::parse($payment->paid_at->toDateString()));

            if ($rate === null) {
                $unconverted[] = __('Fee on payment :reference (:currency)', ['reference' => (string) $payment->reference, 'currency' => $payment->currency]);

                continue;
            }

            $fee = round((float) $payment->fee_amount * $rate, 2);
            $paidOn = $payment->paid_at->toDateString();
            $reversedOn = $payment->reversed_at?->toDateString();

            if ($paidOn >= $start && $paidOn < $until) {
                $lines[] = [$paidOn, 'fees', $fee];
            }

            if ($reversedOn !== null && $reversedOn >= $start && $reversedOn < $until) {
                $lines[] = [$reversedOn, 'fees', -$fee];
            }
        }

        $allocations = PaymentAllocation::query()->withoutGlobalScopes()
            ->with(['receivable' => fn ($query) => $query->withoutGlobalScopes(), 'credit' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('allocated_on', '>=', $start)->where('allocated_on', '<', $until)
            ->get();

        foreach ($allocations as $allocation) {
            $date = $allocation->allocated_on->toDateString();

            if ($allocation->fx_difference !== null && (float) $allocation->fx_difference !== 0.0) {
                $lines[] = [$date, 'customer_exchange', (float) $allocation->fx_difference];
            }

            if ($allocation->credit?->source === BillingCredit::ADJUSTMENT && $allocation->receivable !== null) {
                $rate = $this->rateFor((string) $allocation->receivable->currency, $allocation->receivable->exchange_rate, null);

                if ($rate === null) {
                    $unconverted[] = __('Goodwill credit on an invoice in :currency', ['currency' => $allocation->receivable->currency]);
                } else {
                    $lines[] = [$date, 'goodwill', round((float) $allocation->amount * $rate, 2)];
                }
            }
        }

        $writtenOff = Receivable::query()->withoutGlobalScopes()
            ->where('amount_written_off', '>', 0)
            ->where('written_off_at', '>=', $start)->where('written_off_at', '<', $until)
            ->get();

        foreach ($writtenOff as $receivable) {
            $rate = $this->rateFor((string) $receivable->currency, $receivable->exchange_rate, null);

            if ($rate === null) {
                $unconverted[] = __('Write-off of an invoice in :currency', ['currency' => $receivable->currency]);
            } else {
                [$net, $vat] = $this->writtenOffSplit($receivable, $rate);
                $lines[] = [$receivable->written_off_at->toDateString(), 'bad_debts', $net];
                $lines[] = [$receivable->written_off_at->toDateString(), 'bad_debt_vat', $vat];
            }
        }

        foreach ($this->reliefs($from, $to) as [$date, $vat]) {
            $lines[] = [$date, 'vat_relief', $vat];
        }

        $waived = Expense::query()
            ->where('amount_written_off', '>', 0)
            ->where('written_off_at', '>=', $start)->where('written_off_at', '<', $until)
            ->get();

        foreach ($waived as $expense) {
            $lines[] = [$expense->written_off_at->toDateString(), 'payables_written_off', round((float) $expense->amount_written_off * ($expense->exchange_rate === null ? 1.0 : (float) $expense->exchange_rate), 2)];
        }

        return $lines;
    }

    /**
     * A write-off split into what it was before VAT and its VAT, in the tax
     * currency: the VAT's share of what is written off is the invoice's
     * own (vat_amount / total), the halala difference left on the net.
     *
     * @return array{0: float, 1: float}
     */
    private function writtenOffSplit(Receivable $receivable, float $rate): array
    {
        $gross = round((float) $receivable->amount_written_off * $rate, 2);
        $vat = (float) $receivable->total > 0 ? round($gross * (float) $receivable->vat_amount / (float) $receivable->total, 2) : 0.0;

        return [round($gross - $vat, 2), $vat];
    }

    /**
     * The output VAT the billing company's return takes back under bad-debt
     * relief in the period, by the day each counts from, in the tax
     * currency: relief above zero, a payment after it below (SalesBadDebts,
     * as its VatReturn reads it - the standard-rated share).
     *
     * @return list<array{0: string, 1: float}>
     */
    private function reliefs(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $seller = $this->platform->seller();

        if ($seller === null) {
            return [];
        }

        return $this->tenant->runAs((string) $seller->id, fn (): array => array_map(function (array $row): array {
            $rate = $this->vatSummary->rate($row['invoice'], $this->taxCurrency()) ?? 0.0;
            $tax = array_sum(array_map(fn (array $category): float => $category['code'] === 'S' ? $category['tax'] : 0.0, $this->vatSummary->categories($row['invoice'])));

            return [$row['counts_on'], round($row['share'] * $tax * $rate, 2)];
        }, $this->badDebts->rows((string) $seller->id, $from, $to)));
    }

    /**
     * The rate to the tax currency: 1 in it; the one recorded; else, given
     * a day, the rate table's for it. Null when there is none.
     */
    private function rateFor(string $currency, mixed $recorded, ?CarbonImmutable $on): ?float
    {
        if (strtoupper($currency) === $this->taxCurrency()) {
            return 1.0;
        }

        if ($recorded !== null) {
            return (float) $recorded;
        }

        $table = $on === null ? null : $this->rates->on($currency, $on);

        return $table === null ? null : (float) $table['rate'];
    }

    /**
     * Payments to suppliers in the period, by payment date.
     *
     * @return Collection<int, SupplierPayment>
     */
    private function payments(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return SupplierPayment::query()
            ->where('paid_on', '>=', $from->toDateString())
            ->where('paid_on', '<', $to->addDay()->toDateString())
            ->get();
    }

    /**
     * Revenue deferral, an outline by month: what the platform invoiced
     * before VAT, what of its subscriptions' revenue belongs to the month -
     * each invoice's amount spread evenly over the days of the period it
     * covers (period_start to period_end), one with no period recognised on
     * its issue date - and what is still deferred at the month's end. In the
     * tax currency at each invoice's recorded rate.
     *
     * A report beside the profit and loss, which stays on the documents'
     * tax point (as the VAT return and receivables do): recognising revenue
     * by period in the P&L itself, and how credit notes and write-offs on a
     * period already part recognised are spread, are the owner's decision
     * on the platform's accounting policy. Until then this shows what
     * deferral would move, before credit notes and write-offs.
     *
     * @return array{months: list<array{month: string, invoiced: string, recognised: string, deferred: string}>, unconverted: list<string>}
     */
    public function deferral(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        $unconverted = [];

        for ($month = $from->startOfMonth(); $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = ['month' => $month->format('Y-m'), 'invoiced' => 0.0, 'recognised' => 0.0, 'deferred' => 0.0];
        }

        $receivables = Receivable::query()->withoutGlobalScopes()
            ->where('issue_date', '<', $to->addDay()->toDateString())
            // Issued in the months shown, or covering one of them.
            ->where(fn ($query) => $query->where('period_end', '>=', $from->startOfMonth()->toDateString())
                ->orWhere('issue_date', '>=', $from->startOfMonth()->toDateString()))
            ->get();

        foreach ($receivables as $receivable) {
            $rate = $this->rateFor((string) $receivable->currency, $receivable->exchange_rate, null);

            if ($rate === null) {
                $unconverted[] = __('Invoice in :currency issued :date', ['currency' => $receivable->currency, 'date' => $receivable->issue_date->toDateString()]);

                continue;
            }

            $net = round((float) $receivable->subtotal * $rate, 2);
            $issued = CarbonImmutable::parse($receivable->issue_date->toDateString());
            $start = $receivable->period_start !== null ? CarbonImmutable::parse($receivable->period_start->toDateString()) : $issued;
            $end = $receivable->period_end !== null ? CarbonImmutable::parse($receivable->period_end->toDateString()) : $start;
            $days = max(1, (int) $start->diffInDays($end) + 1);

            foreach ($months as $key => $row) {
                $first = CarbonImmutable::parse($key.'-01');
                $last = $first->endOfMonth()->startOfDay();
                // Through the month's end: recognised, the day it was issued at the earliest.
                $through = fn (CarbonImmutable $day): float => $day->lt($start) ? 0.0 : round($net * min($days, (int) $start->diffInDays($day->min($end)) + 1) / $days, 2);
                $recognisedBy = $issued->gt($last) ? 0.0 : $through($last);
                $recognisedBefore = $issued->gt($first->subDay()) ? 0.0 : $through($first->subDay());

                if ($issued->format('Y-m') === $key) {
                    $months[$key]['invoiced'] += $net;
                }

                $months[$key]['recognised'] += $recognisedBy - $recognisedBefore;
                $months[$key]['deferred'] += $issued->gt($last) ? 0.0 : $net - $recognisedBy;
            }
        }

        return [
            'months' => array_values(array_map(fn (array $row): array => ['month' => $row['month'], ...$this->money($row, ['invoiced', 'recognised', 'deferred'])], $months)),
            'unconverted' => $unconverted,
        ];
    }

    /** The accounts the journal posts to, in the order the trial balance lists them; a bank account is its own. */
    public const ACCOUNTS = ['bank', 'receivables', 'vat_input', 'payables', 'vat_output', 'revenue', 'vat_relief', 'exchange', 'payables_written_off',
        'expenses', 'fees', 'bad_debts', 'bad_debt_vat', 'goodwill'];

    /** @return array<string, string> account => name */
    public static function accountNames(): array
    {
        return [
            'bank' => __('Bank'),
            'receivables' => __('Accounts receivable'),
            'vat_input' => __('VAT input'),
            'payables' => __('Accounts payable'),
            'vat_output' => __('VAT output'),
            'revenue' => __('Revenue'),
            'vat_relief' => __('VAT taken back as bad-debt relief'),
            'exchange' => __('Exchange differences'),
            'payables_written_off' => __('Supplier bills written off'),
            'expenses' => __('Costs'),
            'fees' => __('Bank and card fees'),
            'bad_debts' => __('Bad debts written off'),
            'bad_debt_vat' => __('VAT on bad debts written off'),
            'goodwill' => __('Goodwill credits used'),
        ];
    }

    /**
     * The platform's books for the period as a double-entry journal, in the
     * tax currency, every entry balanced: the platform's own documents
     * (receivables, revenue and output VAT, a credit note the other way),
     * customer payments into their bank account (fees kept apart, a
     * reversal on its own day), money paid back, write-offs (before VAT and
     * its VAT), bad-debt relief, goodwill credits and exchange differences
     * on receipts; the expenses (cost, input VAT, payables, reverse charge
     * on both sides), payments to suppliers with their exchange difference,
     * and bills a supplier waived. The same figures as the profit and loss,
     * each against its balance-sheet account. Anything in another currency
     * with no rate is listed under unconverted, never taken at 1.
     *
     * @return array{lines: list<array{date: string, entry: string, reference: string, account: string, name: string, debit: string, credit: string}>, unconverted: list<string>}
     */
    public function journal(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->toDateString();
        $until = $to->addDay()->toDateString();
        $entries = [];
        $unconverted = [];
        $post = function (string $date, string $entry, string $reference, array $amounts) use (&$entries): void {
            // Debit above zero, credit below: each entry sums to nothing.
            foreach ($amounts as $account => $amount) {
                if (abs($amount) >= 0.005) {
                    $entries[] = [$date, $entry, $reference, $account, round($amount, 2)];
                }
            }
        };

        foreach ($this->finance->vat($from, $to)['documents'] as $document) {
            $net = (float) $document['net_tax'];
            $vat = (float) $document['vat_tax'];
            $post($document['date'], $document['type'] === 'credit_note' ? __('Credit note') : __('Invoice'), $document['number'],
                ['receivables' => $net + $vat, 'revenue' => -$net, 'vat_output' => -$vat]);
        }

        foreach (Payment::query()->withoutGlobalScopes()->where(fn ($query) => $query
            ->where(fn ($paid) => $paid->where('paid_at', '>=', $start)->where('paid_at', '<', $until))
            ->orWhere(fn ($reversed) => $reversed->where('reversed_at', '>=', $start)->where('reversed_at', '<', $until)))->get() as $payment) {
            $rate = $this->rateFor((string) $payment->currency, $payment->exchange_rate, CarbonImmutable::parse($payment->paid_at->toDateString()));

            if ($rate === null) {
                $unconverted[] = __('Payment :reference (:currency)', ['reference' => (string) $payment->reference, 'currency' => $payment->currency]);

                continue;
            }

            $total = round((float) $payment->total * $rate, 2);
            $fee = round((float) $payment->fee_amount * $rate, 2);
            $amounts = [$this->bankAccount((string) $payment->bank_account, $payment->method->value) => $total - $fee, 'fees' => $fee, 'receivables' => -$total];

            if ($payment->paid_at->toDateString() >= $start && $payment->paid_at->toDateString() < $until) {
                $post($payment->paid_at->toDateString(), __('Payment received'), (string) $payment->reference, $amounts);
            }

            if ($payment->reversed_at !== null && $payment->reversed_at->toDateString() >= $start && $payment->reversed_at->toDateString() < $until) {
                $post($payment->reversed_at->toDateString(), __('Payment reversed'), (string) $payment->reference, array_map(fn (float $amount): float => -$amount, $amounts));
            }
        }

        foreach (Refund::query()->withoutGlobalScopes()->where('payout_amount', '>', 0)->where('refunded_on', '>=', $start)->where('refunded_on', '<', $until)->get() as $refund) {
            $rate = $this->rateFor((string) $refund->currency, null, CarbonImmutable::parse($refund->refunded_on->toDateString()));

            if ($rate === null) {
                $unconverted[] = __('Refund :reference (:currency)', ['reference' => (string) $refund->reference, 'currency' => $refund->currency]);

                continue;
            }

            $paid = round((float) $refund->payout_amount * $rate, 2);
            $post($refund->refunded_on->toDateString(), __('Paid back'), (string) $refund->reference, ['receivables' => $paid, $this->bankAccount('', (string) $refund->payout_method) => -$paid]);
        }

        foreach ($this->customerLines($from, $to, $unconverted, false) as [$date, $column, $amount]) {
            match ($column) {
                'bad_debts' => $post($date, __('Written off'), '', ['bad_debts' => $amount, 'receivables' => -$amount]),
                'bad_debt_vat' => $post($date, __('Written off'), '', ['bad_debt_vat' => $amount, 'receivables' => -$amount]),
                'vat_relief' => $post($date, __('Bad-debt relief'), '', ['vat_output' => $amount, 'vat_relief' => -$amount]),
                'goodwill' => $post($date, __('Goodwill credit used'), '', ['goodwill' => $amount, 'receivables' => -$amount]),
                'customer_exchange' => $post($date, __('Exchange difference on a receipt'), '', ['receivables' => $amount, 'exchange' => -$amount]),
                'payables_written_off' => $post($date, __('Supplier bill written off'), '', ['payables' => $amount, 'payables_written_off' => -$amount]),
                default => null,
            };
        }

        foreach ($this->counted($from, $to) as $expense) {
            $net = (float) $expense->net_tax;
            $vat = (float) $expense->vat_tax;
            $post($expense->expense_date->toDateString(), __('Supplier bill'), (string) $expense->supplier_invoice_number, [
                'expenses' => $this->cost($expense),
                'vat_input' => (float) $expense->recoverable_vat_tax,
                'vat_output' => -(float) $expense->self_assessed_vat_tax,
                'payables' => -($net + $vat),
            ]);
        }

        foreach ($this->payments($from, $to) as $paid) {
            $settled = (float) ($paid->settled_tax ?? $paid->amount_tax);
            $post($paid->paid_on->toDateString(), __('Supplier paid'), (string) $paid->reference, [
                'payables' => $settled,
                $this->bankAccount((string) $paid->paid_from, '') => -(float) $paid->amount_tax,
                'exchange' => (float) $paid->amount_tax - $settled,
            ]);
        }

        usort($entries, fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        $names = self::accountNames();

        return [
            'lines' => array_map(fn (array $entry): array => [
                'date' => $entry[0], 'entry' => $entry[1], 'reference' => $entry[2], 'account' => $entry[3],
                'name' => $names[$entry[3]] ?? __('Bank: :account', ['account' => substr($entry[3], 5)]),
                'debit' => Receivable::money(max(0.0, $entry[4])), 'credit' => Receivable::money(max(0.0, -$entry[4])),
            ], $entries),
            'unconverted' => $unconverted,
        ];
    }

    /**
     * The journal's movements in the period by account, debits and credits,
     * which sum to the same: a trial balance of the period. Run from the
     * first day the platform kept books, it is the trial balance as at the
     * last day.
     *
     * @return array{rows: list<array{account: string, name: string, debit: string, credit: string, balance: string}>, debit: string, credit: string}
     */
    public function trialBalance(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sums = [];

        foreach ($this->journal($from, $to)['lines'] as $line) {
            $sums[$line['account']] ??= ['account' => $line['account'], 'name' => $line['name'], 'debit' => 0.0, 'credit' => 0.0];
            $sums[$line['account']]['debit'] += (float) $line['debit'];
            $sums[$line['account']]['credit'] += (float) $line['credit'];
        }

        $order = array_flip(self::ACCOUNTS);
        uksort($sums, fn (string $a, string $b): int => [$order[$a] ?? 0, $a] <=> [$order[$b] ?? 0, $b]);

        return [
            'rows' => array_values(array_map(fn (array $row): array => [...$row, 'debit' => Receivable::money($row['debit']), 'credit' => Receivable::money($row['credit']),
                'balance' => Receivable::money($row['debit'] - $row['credit'])], $sums)),
            'debit' => Receivable::money(array_sum(array_column($sums, 'debit'))),
            'credit' => Receivable::money(array_sum(array_column($sums, 'credit'))),
        ];
    }

    /**
     * Cash by bank account in the period, each in its own currency: what
     * customers paid in (fees kept apart), what was paid back to them and
     * to suppliers, reversals on their own day.
     *
     * @return list<array{account: string, currency: string, received: string, paid_out: string, net: string}>
     */
    public function cash(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->toDateString();
        $until = $to->addDay()->toDateString();
        $rows = [];
        $add = function (string $account, string $currency, float $in, float $out) use (&$rows): void {
            $key = $account.'|'.$currency;
            $rows[$key] ??= ['account' => substr($account, 5), 'currency' => $currency, 'received' => 0.0, 'paid_out' => 0.0];
            $rows[$key]['received'] += $in;
            $rows[$key]['paid_out'] += $out;
        };

        foreach (Payment::query()->withoutGlobalScopes()->where('paid_at', '>=', $start)->where('paid_at', '<', $until)->get() as $payment) {
            $add($this->bankAccount((string) $payment->bank_account, $payment->method->value), (string) $payment->currency, (float) $payment->total - (float) $payment->fee_amount, 0.0);
        }

        foreach (Payment::query()->withoutGlobalScopes()->where('reversed_at', '>=', $start)->where('reversed_at', '<', $until)->get() as $payment) {
            $add($this->bankAccount((string) $payment->bank_account, $payment->method->value), (string) $payment->currency, -((float) $payment->total - (float) $payment->fee_amount), 0.0);
        }

        foreach (Refund::query()->withoutGlobalScopes()->where('payout_amount', '>', 0)->where('refunded_on', '>=', $start)->where('refunded_on', '<', $until)->get() as $refund) {
            $add($this->bankAccount('', (string) $refund->payout_method), (string) $refund->currency, 0.0, (float) $refund->payout_amount);
        }

        foreach ($this->payments($from, $to) as $paid) {
            $add($this->bankAccount((string) $paid->paid_from, ''), (string) $paid->currency, 0.0, (float) $paid->amount);
        }

        ksort($rows);

        return array_values(array_map(fn (array $row): array => [...$row, 'received' => Receivable::money($row['received']), 'paid_out' => Receivable::money($row['paid_out']),
            'net' => Receivable::money($row['received'] - $row['paid_out'])], $rows));
    }

    /**
     * A bank account as the journal names it: bank: and the account's name
     * in settings (its key or name as recorded), else how the money moved -
     * a card's settlements are their own.
     */
    private function bankAccount(string $recorded, string $method): string
    {
        foreach ($this->settings->bankAccounts() as $account) {
            if ($recorded !== '' && in_array($recorded, [$account['key'], $account['account_name'], $account['iban']], true)) {
                return 'bank:'.trim($account['bank_name'].' '.$account['account_name']);
            }
        }

        return 'bank:'.($recorded !== '' ? $recorded : ($method === 'card' ? __('Card settlements') : __('Not named')));
    }

    /**
     * @return array<string, array{rows: array<string, array<string, mixed>>, totals: array<string, string>}>
     */
    public function payables(CarbonImmutable $on): array
    {
        return $this->payables->ageing($on);
    }

    private function cost(Expense $expense): float
    {
        return (float) $expense->net_tax + (float) $expense->vat_tax + (float) $expense->self_assessed_vat_tax - (float) $expense->recoverable_vat_tax;
    }

    /**
     * Recorded in the period and not void, and the suppliers' credit notes
     * dated in it - each as its bill below zero, on the note's date and
     * number, so every report takes it off where the bill counted. Dates
     * compared half-open to the day after, so a date column and a midnight
     * read the same everywhere.
     *
     * @return Collection<int, Expense>
     */
    private function counted(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $expenses = Expense::query()->with('supplier')
            ->whereIn('status', ExpenseStatus::countedValues())
            ->where('expense_date', '>=', $from->toDateString())
            ->where('expense_date', '<', $to->addDay()->toDateString())
            ->orderBy('expense_date')
            ->get();

        $notes = SupplierCreditNote::query()->with(['expense.supplier'])
            ->where('credit_date', '>=', $from->toDateString())
            ->where('credit_date', '<', $to->addDay()->toDateString())
            ->orderBy('credit_date')
            ->get()
            ->filter(fn (SupplierCreditNote $note): bool => $note->expense !== null && $note->expense->counted())
            ->map(fn (SupplierCreditNote $note): Expense => $this->asNegative($note));

        return $expenses->concat($notes)->sortBy(fn (Expense $expense): string => $expense->expense_date->toDateString())->values();
    }

    /**
     * A credit note as its bill below zero: the bill's supplier, category,
     * cost centre and VAT treatment, the note's date, number and amounts.
     * Never saved.
     */
    private function asNegative(SupplierCreditNote $note): Expense
    {
        $bill = $note->expense;
        $negative = new Expense;
        $negative->forceFill([
            'supplier_id' => $bill->supplier_id,
            'expense_date' => $note->credit_date->toDateString(),
            'supplier_invoice_number' => $note->supplier_credit_number,
            'category' => $bill->category,
            'cost_centre' => $bill->cost_centre,
            'currency' => $note->currency,
            'vat_treatment' => $bill->vat_treatment,
            'net_tax' => Receivable::money(-(float) $note->net_tax),
            'vat_tax' => Receivable::money(-(float) $note->vat_tax),
            'self_assessed_vat_tax' => Receivable::money(-(float) $note->self_assessed_vat_tax),
            'recoverable_vat_tax' => Receivable::money(-(float) $note->recoverable_vat_tax),
        ]);
        $negative->setAttribute('credit_note', true);
        $negative->setRelation('supplier', $bill->supplier);

        return $negative;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private function money(array $row, array $columns): array
    {
        $money = [];

        foreach ($columns as $column) {
            $money[$column] = Receivable::money((float) $row[$column]);
        }

        return $money;
    }
}
