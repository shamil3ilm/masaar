<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The platform's expenses as a list: by date, supplier, status, category,
 * cost centre and VAT treatment, and as a CSV of what the list shows.
 */
class ExpenseList
{
    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly Suppliers $suppliers,
    ) {}

    /** @return array<string, string> */
    public static function statuses(): array
    {
        $statuses = [];

        foreach (ExpenseStatus::cases() as $status) {
            $statuses[$status->value] = $status->label();
        }

        return $statuses;
    }

    public function spec(): ListSpec
    {
        return new ListSpec(
            key: 'admin.finance.expenses',
            sorts: [
                'date' => new Sort(__('Date'), 'expense_date'),
                'due' => new Sort(__('Due'), 'due_date', 'asc'),
                'amount' => new Sort(__('Amount'), 'gross_tax'),
            ],
            defaultSort: 'date',
            filters: [
                new Search('q', __('Search'), ['description', 'supplier_invoice_number'], __('Description or invoice number')),
                new Choice('supplier', __('Supplier'), $this->suppliers->names(), 'supplier_id'),
                new Choice('status', __('Status'), self::statuses(), 'status'),
                new Choice('category', __('Category'), $this->settings->expenseCategories(), 'category'),
                new Choice('cost_centre', __('Cost centre'), $this->settings->costCentres(), 'cost_centre'),
                new Choice('vat', __('VAT'), VatTreatment::options(), 'vat_treatment'),
                new DateRange('expense_date', __('Date')),
            ],
        );
    }

    public function page(ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query());
    }

    public function csv(ListState $list, string $fileName): StreamedResponse
    {
        $statuses = self::statuses();
        $categories = $this->settings->expenseCategories();
        $centres = $this->settings->costCentres();

        return CsvExport::download($fileName, [
            __('Date') => fn (Expense $expense): string => $expense->expense_date->toDateString(),
            __('Supplier') => fn (Expense $expense): string => (string) $expense->supplier?->name,
            __('Supplier invoice') => fn (Expense $expense): string => (string) $expense->supplier_invoice_number,
            __('Description') => fn (Expense $expense): string => $expense->description,
            __('Category') => fn (Expense $expense): string => $categories[$expense->category] ?? $expense->category,
            __('Cost centre') => fn (Expense $expense): string => $centres[(string) $expense->cost_centre] ?? (string) $expense->cost_centre,
            __('Status') => fn (Expense $expense): string => $statuses[$expense->status->value] ?? $expense->status->value,
            __('Due') => fn (Expense $expense): string => $expense->due_date->toDateString(),
            __('Currency') => fn (Expense $expense): string => $expense->currency,
            __('Net') => fn (Expense $expense): string => (string) $expense->net,
            __('VAT') => fn (Expense $expense): string => (string) $expense->vat,
            __('Gross') => fn (Expense $expense): string => (string) $expense->gross,
            __('Exchange rate') => fn (Expense $expense): string => $expense->exchange_rate === null ? '' : (string) $expense->exchange_rate,
            __('Tax currency') => fn (Expense $expense): string => $expense->tax_currency,
            __('Net in tax currency') => fn (Expense $expense): string => (string) $expense->net_tax,
            __('VAT in tax currency') => fn (Expense $expense): string => (string) $expense->vat_tax,
            __('VAT treatment') => fn (Expense $expense): string => $expense->vat_treatment->label(),
            __('Recoverable input VAT') => fn (Expense $expense): string => (string) $expense->recoverable_vat_tax,
            __('Paid') => fn (Expense $expense): string => (string) $expense->amount_paid,
            __('Exchange difference') => fn (Expense $expense): string => (string) $expense->exchange_difference,
        ], $list->apply($this->query())->lazy(500));
    }

    /**
     * @return Builder<Expense>
     */
    private function query(): Builder
    {
        return Expense::query()->with('supplier');
    }
}
