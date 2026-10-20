<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Mail\MissingBillsMail;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\RecurringExpense;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The costs the platform expects every month or year (Admin › Finance ›
 * Recurring costs), and the daily run that drafts each bill when it is due
 * and reports the ones not entered in time.
 *
 * A draft is the expected bill with the expected amounts; an admin checks
 * it against the real one and records it (Expenses::confirm). One still a
 * draft finance.missing_bill_days after its date is missing: shown in the
 * console, and reported once in the audit log and the run's log.
 */
class RecurringExpenses
{
    /** At most this many bills of one cost are drafted in one run, catching up. */
    private const CATCH_UP = 12;

    public function __construct(
        private readonly Expenses $expenses,
        private readonly Suppliers $suppliers,
        private readonly FinanceSettings $settings,
        private readonly AuditService $audit,
    ) {}

    public function spec(): ListSpec
    {
        return new ListSpec(
            key: 'admin.finance.recurring',
            sorts: [
                'next' => new Sort(__('Next bill'), 'next_due_on', 'asc'),
                'amount' => new Sort(__('Amount'), 'net'),
            ],
            defaultSort: 'next',
            filters: [
                new Search('q', __('Search'), ['description'], __('Description')),
                new Choice('supplier', __('Supplier'), $this->suppliers->names(), 'supplier_id'),
                new Choice('frequency', __('Frequency'), RecurringExpense::frequencies(), 'frequency'),
                new Choice('showing', __('Showing'), ['paused' => __('Paused'), 'removed' => __('Removed')], fn (QueryBuilder $query, string $value) => null, __('Active')),
            ],
        );
    }

    public function page(ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query($list));
    }

    public function csv(ListState $list, string $fileName): StreamedResponse
    {
        $frequencies = RecurringExpense::frequencies();

        return CsvExport::download($fileName, [
            __('Description') => fn (RecurringExpense $recurring): string => $recurring->description,
            __('Supplier') => fn (RecurringExpense $recurring): string => (string) $recurring->supplier?->name,
            __('Frequency') => fn (RecurringExpense $recurring): string => $frequencies[$recurring->frequency] ?? $recurring->frequency,
            __('Next bill') => fn (RecurringExpense $recurring): string => $recurring->next_due_on->toDateString(),
            __('Currency') => fn (RecurringExpense $recurring): string => $recurring->currency,
            __('Net') => fn (RecurringExpense $recurring): string => (string) $recurring->net,
            __('VAT') => fn (RecurringExpense $recurring): string => (string) $recurring->vat,
            __('VAT treatment') => fn (RecurringExpense $recurring): string => $recurring->vat_treatment->label(),
            __('Active') => fn (RecurringExpense $recurring): string => $recurring->active ? __('Yes') : __('No'),
        ], $list->apply($this->query($list))->lazy(500));
    }

    /**
     * @return Builder<RecurringExpense>
     */
    private function query(ListState $list): Builder
    {
        $query = match ($list->value('showing')) {
            'paused' => RecurringExpense::query()->where('active', false),
            'removed' => RecurringExpense::onlyTrashed(),
            default => RecurringExpense::query()->where('active', true),
        };

        return $query->with('supplier');
    }

    public function find(string $id): ?RecurringExpense
    {
        return RecurringExpense::withTrashed()->with('supplier')->find($id);
    }

    /**
     * Add one, or change it. The supplier, category and cost centre are
     * those the lists offer now.
     *
     * @param  array<string, mixed>  $data  as the form sends it
     *
     * @throws ValidationException
     */
    public function save(?RecurringExpense $recurring, array $data, ?string $actorId): RecurringExpense
    {
        $recurring ??= new RecurringExpense;
        $treatment = VatTreatment::from((string) $data['vat_treatment']);
        $supplier = $this->suppliers->find((string) $data['supplier_id']);

        if ($supplier === null || ((string) $supplier->id !== (string) $recurring->supplier_id && ($supplier->trashed() || $supplier->archived()))) {
            throw ValidationException::withMessages(['supplier_id' => __('Choose a supplier from the list.')]);
        }

        if ($treatment === VatTreatment::ReverseCharge && ! $this->expenses->nonResident($supplier)) {
            throw ValidationException::withMessages(['vat_treatment' => __(':name is resident in the platform\'s own country, so its VAT is on its invoice, not reverse charge.', ['name' => $supplier->name])]);
        }

        if (! array_key_exists((string) $data['category'], $this->settings->expenseCategories())) {
            throw ValidationException::withMessages(['category' => __('Choose a category from the list.')]);
        }

        if (($data['cost_centre'] ?? null) !== null && ! array_key_exists((string) $data['cost_centre'], $this->settings->costCentres())) {
            throw ValidationException::withMessages(['cost_centre' => __('Choose a cost centre from the list.')]);
        }

        if (! in_array(strtoupper((string) $data['currency']), FinanceSettings::expenseCurrencies(), true)) {
            throw ValidationException::withMessages(['currency' => __('Expenses are not kept in that currency.')]);
        }

        if (! $treatment->charged() && (float) ($data['vat'] ?? 0) > 0) {
            throw ValidationException::withMessages(['vat' => __('VAT is entered only when the supplier charged it: :treatment has none.', ['treatment' => $treatment->label()])]);
        }

        $before = $recurring->exists ? $recurring->only(['net', 'vat', 'frequency', 'next_due_on', 'active']) : null;

        $recurring->fill([
            'supplier_id' => (string) $data['supplier_id'],
            'description' => trim((string) $data['description']),
            'category' => (string) $data['category'],
            'cost_centre' => $data['cost_centre'] ?? null,
            'currency' => strtoupper((string) $data['currency']),
            'net' => Receivable::money((float) $data['net']),
            'vat' => Receivable::money((float) ($data['vat'] ?? 0)),
            'vat_treatment' => $treatment,
            'frequency' => (string) $data['frequency'],
            'next_due_on' => CarbonImmutable::parse((string) $data['next_due_on'])->toDateString(),
            'active' => (bool) ($data['active'] ?? true),
            'notes' => ($data['notes'] ?? '') === '' ? null : (string) $data['notes'],
        ]);

        // The next date an admin gives sets the day its bills fall on.
        if (! $recurring->exists || $recurring->isDirty('next_due_on') || $recurring->anchor_day === null) {
            $recurring->anchor_day = $recurring->next_due_on->day;
        }

        $recurring->save();

        $this->audit->logChange($before === null ? 'finance.recurring_expense.created' : 'finance.recurring_expense.updated', 'RecurringExpense', (string) $recurring->id,
            $before, $recurring->only(['net', 'vat', 'frequency', 'next_due_on', 'active']), [], $actorId);

        return $recurring;
    }

    public function remove(RecurringExpense $recurring, ?string $actorId): void
    {
        $recurring->delete();
        $this->audit->logChange('finance.recurring_expense.removed', 'RecurringExpense', (string) $recurring->id, null, null, [], $actorId);
    }

    public function restore(RecurringExpense $recurring, ?string $actorId): void
    {
        $recurring->restore();
        $this->audit->logChange('finance.recurring_expense.restored', 'RecurringExpense', (string) $recurring->id, null, null, [], $actorId);
    }

    /**
     * Draft every bill that has fallen due by a day, then report those
     * missing. A cost whose currency has no rate yet is left due, and said
     * so in the run's log, to be drafted when there is one.
     *
     * @return array{drafted: int, missing: int, failed: list<string>}
     */
    public function run(CarbonImmutable $today): array
    {
        $drafted = 0;
        $failed = [];

        $due = RecurringExpense::query()->where('active', true)->where('next_due_on', '<', $today->addDay()->toDateString())->get();

        foreach ($due as $recurring) {
            for ($i = 0; $i < self::CATCH_UP && $recurring->next_due_on->toDateString() <= $today->toDateString(); $i++) {
                $date = CarbonImmutable::parse($recurring->next_due_on->toDateString());

                try {
                    $this->expenses->draftFrom($recurring, $date);
                } catch (ValidationException $e) {
                    $failed[] = $recurring->description.': '.collect($e->errors())->flatten()->first();
                    Log::warning('A recurring cost could not be drafted.', ['recurring_expense_id' => $recurring->id, 'errors' => $e->errors()]);

                    break;
                }

                $recurring->forceFill(['next_due_on' => $recurring->after($date)->toDateString(), 'last_drafted_on' => $date->toDateString()])->save();
                $drafted++;
            }
        }

        return ['drafted' => $drafted, 'missing' => $this->report($today), 'failed' => $failed];
    }

    /**
     * Bills expected and still not entered: drafts of a recurring cost the
     * set number of days after their date.
     *
     * @return Collection<int, Expense>
     */
    public function missing(CarbonImmutable $today): Collection
    {
        return $this->missingQuery($today)->with('supplier')->orderBy('expense_date')->get();
    }

    /** How many, for the console's menu. */
    public function missingCount(CarbonImmutable $today): int
    {
        return $this->missingQuery($today)->count();
    }

    /**
     * @return Builder<Expense>
     */
    private function missingQuery(CarbonImmutable $today): Builder
    {
        return Expense::query()
            ->whereNotNull('recurring_expense_id')
            ->where('status', ExpenseStatus::Draft->value)
            ->where('expense_date', '<', $today->subDays($this->settings->missingBillDays())->addDay()->toDateString());
    }

    /** Each newly missing bill on the audit log and the run's log, once. */
    private function report(CarbonImmutable $today): int
    {
        $missing = $this->missing($today);
        $new = $missing->whereNull('missing_reported_at')->values();

        foreach ($new as $expense) {
            $expense->forceFill(['missing_reported_at' => now()])->saveQuietly();
            $this->audit->logChange('finance.expense.missing', 'Expense', (string) $expense->id, null, null, [
                'supplier' => (string) $expense->supplier?->name, 'expected_on' => $expense->expense_date->toDateString(),
            ]);
            Log::warning('An expected bill has not been entered.', ['expense_id' => $expense->id, 'expected_on' => $expense->expense_date->toDateString()]);
        }

        $this->tell($new);

        return $missing->count();
    }

    /**
     * The newly missing bills, emailed once - missing_reported_at is set
     * before this, so no later run sends them again - to every admin who
     * pays suppliers (billing.manage) and has not turned it off.
     *
     * @param  Collection<int, Expense>  $bills
     */
    private function tell(Collection $bills): void
    {
        if ($bills->isEmpty() || ! $this->settings->missingBillEmails()) {
            return;
        }

        $lines = $bills->map(fn (Expense $expense): array => [
            'supplier' => (string) $expense->supplier?->name,
            'description' => $expense->description,
            'expected_on' => $expense->expense_date->toDateString(),
            'amount' => $expense->gross.' '.$expense->currency,
            'url' => route('admin.finance.expenses.show', $expense->id),
        ])->all();

        $admins = User::query()->where('is_platform_admin', true)->get()
            ->filter(fn (User $admin): bool => $admin->canAdmin(AdminPermission::BillingManage) && $this->settings->missingBillEmails((string) $admin->id));

        foreach ($admins as $admin) {
            Mail::to($admin)->queue(new MissingBillsMail($lines, route('admin.finance.recurring')));
        }
    }
}
