<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\SupplierCreditNote;
use App\Domains\Billing\Models\SupplierPayment;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the platform owes its suppliers: the expenses recorded and not yet
 * paid or written off, and the same by how long past due, per supplier and
 * currency - the buckets the receivables' ageing uses (Statements).
 */
class Payables
{
    public function __construct(
        private readonly Suppliers $suppliers,
        private readonly Statements $statements,
    ) {}

    public function spec(): ListSpec
    {
        $currencies = FinanceSettings::expenseCurrencies();

        return new ListSpec(
            key: 'admin.finance.payables',
            sorts: [
                'due' => new Sort(__('Due'), 'due_date', 'asc'),
                'amount' => new Sort(__('Amount'), 'gross_tax'),
            ],
            defaultSort: 'due',
            filters: [
                new Choice('supplier', __('Supplier'), $this->suppliers->names(), 'supplier_id'),
                new Choice('currency', __('Currency'), array_combine($currencies, $currencies), 'currency'),
                new Choice('overdue', __('Showing'), ['overdue' => __('Past due')], fn (QueryBuilder $query, string $value) => $query->where('due_date', '<', now()->toDateString()), __('Everything owed')),
            ],
        );
    }

    public function page(ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query());
    }

    public function csv(ListState $list, string $fileName): StreamedResponse
    {
        return CsvExport::download($fileName, [
            __('Supplier') => fn (Expense $expense): string => (string) $expense->supplier?->name,
            __('Supplier invoice') => fn (Expense $expense): string => (string) $expense->supplier_invoice_number,
            __('Date') => fn (Expense $expense): string => $expense->expense_date->toDateString(),
            __('Due') => fn (Expense $expense): string => $expense->due_date->toDateString(),
            __('Currency') => fn (Expense $expense): string => $expense->currency,
            __('Total') => fn (Expense $expense): string => (string) $expense->gross,
            __('Paid') => fn (Expense $expense): string => (string) $expense->amount_paid,
            __('Owed') => fn (Expense $expense): string => $expense->balance(),
            __('Exchange difference') => fn (Expense $expense): string => (string) $expense->exchange_difference,
        ], $list->apply($this->query())->lazy(500));
    }

    /**
     * Owed by age at the end of a day, per currency: a row per supplier and
     * the currency's totals, each bucket as "0.00".
     *
     * As it stood that day: bills dated by then, less what was paid on
     * them (a reversal on its own day), credited by the supplier and
     * written off by then - so a past month-end reads the same later. A
     * bill voided since was still owed on the day.
     *
     * @return array<string, array{rows: array<string, array<string, mixed>>, totals: array<string, string>}>
     */
    public function ageing(CarbonImmutable $on): array
    {
        $buckets = [...Statements::BUCKETS, 'total'];
        $until = $on->startOfDay()->addDay();
        $ageing = [];

        $expenses = Expense::query()->with('supplier')
            // A bill voided after the day was owed on it.
            ->where(fn ($counted) => $counted->whereIn('status', ExpenseStatus::countedValues())
                ->orWhere(fn ($void) => $void->where('status', ExpenseStatus::Void->value)->where('voided_at', '>=', $until)))
            ->where('expense_date', '<', $until->toDateString())
            ->get();
        $paid = SupplierPayment::query()
            ->whereIn('expense_id', $expenses->modelKeys())
            ->where('paid_on', '<', $until->toDateString())
            ->groupBy('expense_id')
            ->selectRaw('expense_id, sum(amount) as paid')
            ->pluck('paid', 'expense_id');
        $credited = SupplierCreditNote::query()
            ->whereIn('expense_id', $expenses->modelKeys())
            ->where('credit_date', '<', $until->toDateString())
            ->groupBy('expense_id')
            ->selectRaw('expense_id, sum(gross) as credited')
            ->pluck('credited', 'expense_id');

        foreach ($expenses as $expense) {
            $writtenOff = $expense->written_off_at !== null && $expense->written_off_at->lt($until) ? (float) $expense->amount_written_off : 0.0;
            $owed = round(max(0.0, (float) $expense->gross - (float) ($paid[$expense->id] ?? 0) - (float) ($credited[$expense->id] ?? 0) - $writtenOff), 2);

            if ($owed <= 0.0) {
                continue;
            }

            $currency = $expense->currency;
            $supplier = (string) $expense->supplier_id;
            $ageing[$currency]['rows'][$supplier] ??= ['supplier_id' => $supplier, 'name' => (string) $expense->supplier?->name, ...array_fill_keys($buckets, 0.0)];
            $ageing[$currency]['totals'] ??= array_fill_keys($buckets, 0.0);

            $bucket = $this->statements->bucket($expense->daysOverdue($on));

            foreach ([$bucket, 'total'] as $column) {
                $ageing[$currency]['rows'][$supplier][$column] += $owed;
                $ageing[$currency]['totals'][$column] += $owed;
            }
        }

        foreach ($ageing as $currency => $table) {
            uasort($table['rows'], fn (array $a, array $b): int => $b['total'] <=> $a['total']);

            $ageing[$currency] = [
                'rows' => array_map(fn (array $row): array => [...$row, ...array_map(fn ($value): string => Receivable::money((float) $value), array_intersect_key($row, array_flip($buckets)))], $table['rows']),
                'totals' => array_map(fn ($value): string => Receivable::money((float) $value), $table['totals']),
            ];
        }

        ksort($ageing);

        return $ageing;
    }

    /**
     * @return Builder<Expense>
     */
    private function query(): Builder
    {
        return Expense::query()->with('supplier')->whereIn('status', ExpenseStatus::owedValues());
    }
}
