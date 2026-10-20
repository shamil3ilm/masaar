<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\DTOs\ExpenseEntry;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Services\ExpenseList;
use App\Domains\Billing\Services\ExpenseReceipts;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\PlatformInvoices;
use App\Domains\Billing\Services\Suppliers;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin › Finance › Expenses: what the platform spent, with its receipts.
 * Every admin reads them; entering, changing, recording a draft and voiding
 * take billing.manage.
 */
class AdminExpensesController extends Controller
{
    public function __construct(
        private readonly Expenses $expenses,
        private readonly ExpenseList $list,
        private readonly ExpenseReceipts $receipts,
        private readonly Suppliers $suppliers,
        private readonly FinanceSettings $settings,
        private readonly PlatformInvoices $platform,
        private readonly Lists $lists,
    ) {}

    public function index(Request $request): View
    {
        $list = $this->listState($request, 'admin.finance.expenses.export');

        return view('admin.finance.expenses.index', [
            'expenses' => $this->list->page($list),
            'list' => $list,
            'categories' => $this->settings->expenseCategories(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->list->csv($this->listState($request), 'expenses-'.now()->format('Y-m-d').'.csv');
    }

    public function create(Request $request): View
    {
        $supplier = $this->suppliers->find((string) $request->query('supplier', ''));

        return $this->form(new Expense([
            'supplier_id' => $supplier?->id,
            'expense_date' => now()->toDateString(),
            'currency' => $supplier->currency ?? $this->platform->taxCurrency(),
            'category' => $supplier->category ?? array_key_first($this->settings->expenseCategories()),
            'vat_treatment' => $this->expenses->treatmentFor($supplier),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $expense = $this->expenses->record($this->entry($data), $request->file('receipt'), $request->boolean('draft'), $this->actor($request, null));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.finance.expenses.show', $expense->id)->with('success', $request->boolean('draft') ? __('Expense saved as a draft.') : __('Expense recorded.'));
    }

    public function show(string $expenseId): View
    {
        return view('admin.finance.expenses.show', [
            'expense' => $this->expense($expenseId)->load('payments'),
            'categories' => $this->settings->expenseCategories(),
            'centres' => $this->settings->costCentres(),
            'threshold' => $this->settings->largePaymentThreshold(),
        ]);
    }

    public function edit(string $expenseId): View
    {
        $expense = $this->expense($expenseId);

        abort_unless($expense->editable(), 404);

        return $this->form($expense);
    }

    public function update(Request $request, string $expenseId): RedirectResponse
    {
        $expense = $this->expense($expenseId);
        $data = $this->validated($request);

        try {
            $this->expenses->update($expense, $this->entry($data), $request->file('receipt'), $this->actor($request, $expenseId));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.finance.expenses.show', $expenseId)->with('success', __('Expense saved.'));
    }

    /** A draft whose bill came, recorded. */
    public function confirm(Request $request, string $expenseId): RedirectResponse
    {
        try {
            $this->expenses->confirm($this->expense($expenseId), $this->actor($request, $expenseId));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.finance.expenses.show', $expenseId)->with('success', __('Expense recorded.'));
    }

    public function void(Request $request, string $expenseId): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->expenses->void($this->expense($expenseId), $data['reason'], $this->actor($request, $expenseId));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.finance.expenses.show', $expenseId)->with('success', __('Expense void.'));
    }

    /** The receipt, as a download. */
    public function receipt(string $expenseId): BinaryFileResponse
    {
        return $this->file($this->expense($expenseId), ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    /**
     * The receipt in the browser: an image shown on the expense's page, a
     * PDF opened on its own. Only those types are ever stored.
     */
    public function preview(string $expenseId): BinaryFileResponse
    {
        return $this->file($this->expense($expenseId), ResponseHeaderBag::DISPOSITION_INLINE);
    }

    private function file(Expense $expense, string $disposition): BinaryFileResponse
    {
        abort_unless($this->receipts->exists($expense->receipt_path), 404);

        return response()->file($this->receipts->absolutePath((string) $expense->receipt_path), [
            'Content-Type' => (string) $expense->receipt_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ])->setContentDisposition($disposition, (string) $expense->receipt_name);
    }

    private function form(Expense $expense): View
    {
        return view('admin.finance.expenses.form', [
            'expense' => $expense,
            'suppliers' => $this->suppliers->choosable($expense->supplier_id),
            'categories' => $this->settings->expenseCategories(),
            'centres' => $this->settings->costCentres(),
            'currencies' => FinanceSettings::expenseCurrencies(),
            'treatments' => VatTreatment::options(),
            'taxCurrency' => $this->platform->taxCurrency(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:expense_date'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:80'],
            'description' => ['required', 'string', 'max:300'],
            'category' => ['required', 'string', 'max:40'],
            'cost_centre' => ['nullable', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'net' => ['required', 'numeric', 'max:99999999'],
            'vat' => ['nullable', 'numeric', 'max:99999999'],
            'vat_treatment' => ['required', Rule::enum(VatTreatment::class)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'max:'.ExpenseReceipts::MAX_KILOBYTES],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function entry(array $data): ExpenseEntry
    {
        return new ExpenseEntry(
            supplierId: (string) $data['supplier_id'],
            expenseDate: CarbonImmutable::parse($data['expense_date']),
            description: (string) $data['description'],
            category: (string) $data['category'],
            currency: (string) $data['currency'],
            net: (string) $data['net'],
            vat: (string) ($data['vat'] ?? '0'),
            vatTreatment: VatTreatment::from((string) $data['vat_treatment']),
            supplierInvoiceNumber: $data['supplier_invoice_number'] ?? null,
            costCentre: ($data['cost_centre'] ?? '') === '' ? null : (string) $data['cost_centre'],
            exchangeRate: isset($data['exchange_rate']) ? (string) $data['exchange_rate'] : null,
            notes: $data['notes'] ?? null,
            dueDate: isset($data['due_date']) ? CarbonImmutable::parse($data['due_date']) : null,
        );
    }

    private function actor(Request $request, ?string $expenseId): string
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Expense', $expenseId]);

        return (string) $request->user()?->id;
    }

    private function expense(string $expenseId): Expense
    {
        return $this->expenses->find($expenseId) ?? abort(404);
    }

    private function listState(Request $request, ?string $export = null): ListState
    {
        return $this->lists->admin($this->list->spec(), $request, $export);
    }
}
