<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\RecurringExpense;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\PlatformInvoices;
use App\Domains\Billing\Services\RecurringExpenses;
use App\Domains\Billing\Services\Suppliers;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin › Finance › Recurring costs: what the platform expects to pay every
 * month or year, and the bills expected and not yet entered. Every admin
 * reads them; changing them takes billing.manage.
 */
class AdminRecurringExpensesController extends Controller
{
    public function __construct(
        private readonly RecurringExpenses $recurring,
        private readonly Expenses $expenses,
        private readonly Suppliers $suppliers,
        private readonly FinanceSettings $settings,
        private readonly PlatformInvoices $platform,
        private readonly Lists $lists,
    ) {}

    public function index(Request $request): View
    {
        $list = $this->listState($request, 'admin.finance.recurring.export');

        return view('admin.finance.recurring.index', [
            'recurring' => $this->recurring->page($list),
            'list' => $list,
            'missing' => $this->recurring->missing(CarbonImmutable::now()->startOfDay()),
            'missingDays' => $this->settings->missingBillDays(),
            'frequencies' => RecurringExpense::frequencies(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->recurring->csv($this->listState($request), 'recurring-costs-'.now()->format('Y-m-d').'.csv');
    }

    public function create(Request $request): View
    {
        $supplier = $this->suppliers->find((string) $request->query('supplier', ''));

        return $this->form(new RecurringExpense([
            'supplier_id' => $supplier?->id,
            'currency' => $supplier->currency ?? $this->platform->taxCurrency(),
            'category' => $supplier->category ?? array_key_first($this->settings->expenseCategories()),
            'vat_treatment' => $this->expenses->treatmentFor($supplier),
            'frequency' => RecurringExpense::MONTHLY,
            'next_due_on' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
            'active' => true,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $recurring = $this->recurring->save(null, $this->validated($request), $this->actor($request, null));

        return redirect()->route('admin.finance.recurring.show', $recurring->id)->with('success', __('Recurring cost added. Its next bill is drafted on :date.', ['date' => $recurring->next_due_on->toDateString()]));
    }

    public function show(string $recurringId): View
    {
        return $this->form($this->find($recurringId));
    }

    public function update(Request $request, string $recurringId): RedirectResponse
    {
        $this->recurring->save($this->find($recurringId), $this->validated($request), $this->actor($request, $recurringId));

        return redirect()->route('admin.finance.recurring.show', $recurringId)->with('success', __('Recurring cost saved.'));
    }

    public function remove(Request $request, string $recurringId): RedirectResponse
    {
        $this->recurring->remove($this->find($recurringId), $this->actor($request, $recurringId));

        return redirect()->route('admin.finance.recurring')->with('success', __('Recurring cost removed. No more bills are expected from it.'));
    }

    public function restore(Request $request, string $recurringId): RedirectResponse
    {
        $this->recurring->restore($this->find($recurringId), $this->actor($request, $recurringId));

        return redirect()->route('admin.finance.recurring.show', $recurringId)->with('success', __('Recurring cost restored.'));
    }

    private function form(RecurringExpense $recurring): View
    {
        return view('admin.finance.recurring.form', [
            'recurring' => $recurring,
            'suppliers' => $this->suppliers->choosable($recurring->supplier_id),
            'categories' => $this->settings->expenseCategories(),
            'centres' => $this->settings->costCentres(),
            'currencies' => FinanceSettings::expenseCurrencies(),
            'treatments' => VatTreatment::options(),
            'frequencies' => RecurringExpense::frequencies(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:300'],
            'category' => ['required', 'string', 'max:40'],
            'cost_centre' => ['nullable', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'net' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'vat_treatment' => ['required', Rule::enum(VatTreatment::class)],
            'frequency' => ['required', Rule::in(array_keys(RecurringExpense::frequencies()))],
            'next_due_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return [...$data, 'cost_centre' => ($data['cost_centre'] ?? '') === '' ? null : $data['cost_centre'], 'active' => $request->boolean('active')];
    }

    private function actor(Request $request, ?string $recurringId): string
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['RecurringExpense', $recurringId]);

        return (string) $request->user()?->id;
    }

    private function find(string $recurringId): RecurringExpense
    {
        return $this->recurring->find($recurringId) ?? abort(404);
    }

    private function listState(Request $request, ?string $export = null): ListState
    {
        return $this->lists->admin($this->recurring->spec(), $request, $export);
    }
}
