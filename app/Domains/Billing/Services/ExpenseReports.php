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
use App\Domains\Billing\Models\SupplierCreditNote;
use App\Domains\Billing\Models\SupplierPayment;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Compliance\Engine\DTOs\VatReturnEntry;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
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
    public const REPORTS = ['category', 'supplier', 'month', 'cost_centre', 'vat', 'pnl', 'payables'];

    public const GROUPS = ['category', 'supplier', 'month', 'cost_centre'];

    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly FinanceReports $finance,
        private readonly Payables $payables,
        private readonly PlatformInvoices $platform,
        private readonly ExchangeRates $rates,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly TenantResolver $tenant,
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

        return [
            'summary' => [
                'sales_net' => $sales['net'],
                'sales_output' => $sales['vat'],
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
    public const PNL_COLUMNS = ['revenue', 'costs', 'exchange', 'customer_exchange', 'fees', 'bad_debts', 'payables_written_off', 'goodwill'];

    /** What each column does to profit: income adds, a cost takes away. */
    private const PNL_SIGNS = ['revenue' => 1, 'costs' => -1, 'exchange' => 1, 'customer_exchange' => 1, 'fees' => -1, 'bad_debts' => -1, 'payables_written_off' => 1, 'goodwill' => -1];

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
     *    (a reconciliation shortfall taken as a fee, or the gateway's),
     *    payments reversed as recorded in error left out - or, as Finance ›
     *    Settings chooses, the bills in a fee category instead, never both;
     *  - bad_debts: invoices written off, with their VAT - declared and
     *    never collected - on the day written off;
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
                $lines[] = [$receivable->written_off_at->toDateString(), 'bad_debts', round((float) $receivable->amount_written_off * $rate, 2)];
            }
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
