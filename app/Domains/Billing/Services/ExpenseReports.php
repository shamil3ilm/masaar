<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\SupplierPayment;
use App\Domains\Billing\Settings\FinanceSettings;
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
            $rows[$key]['count']++;
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
        $sales = $this->finance->vat($from, $to)['totals'];
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
            'tax_currency' => $this->taxCurrency(),
        ];
    }

    /**
     * Profit and loss, an outline by month: revenue - the platform's own
     * documents before VAT, credit notes taking away (FinanceReports::vat) -
     * less what its expenses cost, plus the exchange differences realised
     * on paying suppliers in the month they were paid (a gain adds).
     *
     * @return array{months: list<array{month: string, revenue: string, costs: string, exchange: string, profit: string}>, totals: array{revenue: string, costs: string, exchange: string, profit: string}}
     */
    public function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $blank = fn (string $key): array => ['month' => $key, 'revenue' => 0.0, 'costs' => 0.0, 'exchange' => 0.0];
        $months = [];

        for ($month = $from->startOfMonth(); $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = $blank($month->format('Y-m'));
        }

        foreach ($this->finance->vat($from, $to)['documents'] as $document) {
            $key = substr($document['date'], 0, 7);
            $months[$key] ??= $blank($key);
            $months[$key]['revenue'] += (float) $document['net_tax'];
        }

        foreach ($this->counted($from, $to) as $expense) {
            $key = $expense->expense_date->format('Y-m');
            $months[$key] ??= $blank($key);
            $months[$key]['costs'] += $this->cost($expense);
        }

        foreach ($this->payments($from, $to) as $payment) {
            $key = $payment->paid_on->format('Y-m');
            $months[$key] ??= $blank($key);
            $months[$key]['exchange'] += (float) $payment->exchange_difference;
        }

        ksort($months);
        $totals = ['revenue' => 0.0, 'costs' => 0.0, 'exchange' => 0.0];

        foreach ($months as $row) {
            foreach (array_keys($totals) as $column) {
                $totals[$column] += $row[$column];
            }
        }

        $finish = fn (array $row): array => [...$this->money($row, ['revenue', 'costs', 'exchange']), 'profit' => Receivable::money($row['revenue'] - $row['costs'] + $row['exchange'])];

        return [
            'months' => array_values(array_map(fn (array $row): array => ['month' => $row['month'], ...$finish($row)], $months)),
            'totals' => $finish($totals),
        ];
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
     * Recorded in the period and not void. Dates compared half-open to the
     * day after, so a date column and a midnight read the same everywhere.
     *
     * @return Collection<int, Expense>
     */
    private function counted(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Expense::query()->with('supplier')
            ->whereIn('status', ExpenseStatus::countedValues())
            ->where('expense_date', '>=', $from->toDateString())
            ->where('expense_date', '<', $to->addDay()->toDateString())
            ->orderBy('expense_date')
            ->get();
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
