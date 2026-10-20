<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Receivable;
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
     * Owed by age on a day, per currency: a row per supplier and the
     * currency's totals, each bucket as "0.00".
     *
     * @return array<string, array{rows: array<string, array<string, mixed>>, totals: array<string, string>}>
     */
    public function ageing(CarbonImmutable $on): array
    {
        $buckets = [...Statements::BUCKETS, 'total'];
        $ageing = [];

        foreach ($this->query()->get() as $expense) {
            $currency = $expense->currency;
            $supplier = (string) $expense->supplier_id;
            $ageing[$currency]['rows'][$supplier] ??= ['supplier_id' => $supplier, 'name' => (string) $expense->supplier?->name, ...array_fill_keys($buckets, 0.0)];
            $ageing[$currency]['totals'] ??= array_fill_keys($buckets, 0.0);

            $bucket = $this->statements->bucket($expense->daysOverdue($on));
            $owed = (float) $expense->balance();

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
