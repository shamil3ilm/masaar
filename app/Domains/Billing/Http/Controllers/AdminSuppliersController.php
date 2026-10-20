<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Models\Supplier;
use App\Domains\Billing\Services\Suppliers;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin › Finance › Suppliers: who the platform pays. Every admin reads
 * them; adding, changing, archiving and removing take billing.manage.
 */
class AdminSuppliersController extends Controller
{
    public function __construct(
        private readonly Suppliers $suppliers,
        private readonly FinanceSettings $settings,
        private readonly Lists $lists,
    ) {}

    public function index(Request $request): View
    {
        $list = $this->listState($request, 'admin.finance.suppliers.export');

        return view('admin.finance.suppliers.index', [
            'suppliers' => $this->suppliers->page($list),
            'list' => $list,
            'categories' => $this->settings->expenseCategories(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->suppliers->csv($this->listState($request), 'suppliers-'.now()->format('Y-m-d').'.csv');
    }

    public function create(): View
    {
        return $this->form(new Supplier(['currency' => $this->settings->currencyFor(), 'payment_terms_days' => 30]));
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = $this->suppliers->save(null, $this->validated($request), (string) Auth::id());
        $request->attributes->set(AuditAdminAction::TARGET, ['Supplier', (string) $supplier->id]);

        return redirect()->route('admin.finance.suppliers.show', $supplier->id)->with('success', __('Supplier :name added.', ['name' => $supplier->name]));
    }

    public function show(string $supplierId): View
    {
        return $this->form($this->supplier($supplierId));
    }

    public function update(Request $request, string $supplierId): RedirectResponse
    {
        $supplier = $this->target($request, $supplierId);
        $this->suppliers->save($supplier, $this->validated($request), (string) Auth::id());

        return redirect()->route('admin.finance.suppliers.show', $supplierId)->with('success', __('Supplier :name saved.', ['name' => $supplier->name]));
    }

    public function archive(Request $request, string $supplierId): RedirectResponse
    {
        $supplier = $this->target($request, $supplierId);
        $this->suppliers->archive($supplier, (string) Auth::id());

        return redirect()->route('admin.finance.suppliers.show', $supplierId)->with('success', __('Supplier :name archived.', ['name' => $supplier->name]));
    }

    public function unarchive(Request $request, string $supplierId): RedirectResponse
    {
        $supplier = $this->target($request, $supplierId);
        $this->suppliers->unarchive($supplier, (string) Auth::id());

        return redirect()->route('admin.finance.suppliers.show', $supplierId)->with('success', __('Supplier :name is back in the lists.', ['name' => $supplier->name]));
    }

    public function remove(Request $request, string $supplierId): RedirectResponse
    {
        $supplier = $this->target($request, $supplierId);

        try {
            $this->suppliers->remove($supplier, (string) Auth::id());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.finance.suppliers')->with('success', __('Supplier :name removed.', ['name' => $supplier->name]));
    }

    public function restore(Request $request, string $supplierId): RedirectResponse
    {
        $supplier = $this->target($request, $supplierId);
        $this->suppliers->restore($supplier, (string) Auth::id());

        return redirect()->route('admin.finance.suppliers.show', $supplierId)->with('success', __('Supplier :name restored.', ['name' => $supplier->name]));
    }

    private function form(Supplier $supplier): View
    {
        return view('admin.finance.suppliers.form', [
            'supplier' => $supplier,
            'categories' => $this->settings->expenseCategories(),
            'currencies' => FinanceSettings::expenseCurrencies(),
            'inUse' => $supplier->exists && $this->suppliers->inUse($supplier),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'country' => ['required', 'string', 'size:2'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'currency' => ['required', 'string', 'size:3'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'iban' => ['nullable', 'string', 'max:42'],
            'swift' => ['nullable', 'string', 'max:11'],
            'category' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function target(Request $request, string $supplierId): Supplier
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Supplier', $supplierId]);

        return $this->supplier($supplierId);
    }

    private function supplier(string $supplierId): Supplier
    {
        return $this->suppliers->find($supplierId) ?? abort(404);
    }

    private function listState(Request $request, ?string $export = null): ListState
    {
        return $this->lists->admin($this->suppliers->spec(), $request, $export);
    }
}
