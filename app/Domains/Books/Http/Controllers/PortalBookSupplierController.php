<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Books\Services\Suppliers;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The organization's suppliers, for its purchase records: listed, added,
 * changed and removed. BooksAccess decides who may change them.
 */
class PortalBookSupplierController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly Suppliers $suppliers,
    ) {}

    public function index(Request $request): View
    {
        $organizationId = $this->portalOrganizationId($request);

        return view('portal.books.suppliers', [
            'suppliers' => $this->suppliers->all($organizationId),
            'records' => MemberRole::keepsBooks($request->user()?->roleIn($organizationId)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->suppliers->save($this->portalOrganizationId($request), $this->validated($request));

        return redirect()->route('portal.books.suppliers.index')->with('success', __('Supplier saved.'));
    }

    public function edit(Request $request, string $bookSupplierId): View
    {
        $organizationId = $this->portalOrganizationId($request);

        return view('portal.books.supplier', [
            'supplier' => $this->suppliers->find($organizationId, $bookSupplierId) ?? abort(404),
            'records' => MemberRole::keepsBooks($request->user()?->roleIn($organizationId)),
        ]);
    }

    public function update(Request $request, string $bookSupplierId): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);
        $supplier = $this->suppliers->find($organizationId, $bookSupplierId) ?? abort(404);
        $this->suppliers->save($organizationId, $this->validated($request), $supplier);

        return redirect()->route('portal.books.suppliers.index')->with('success', __('Supplier saved.'));
    }

    public function destroy(Request $request, string $bookSupplierId): RedirectResponse
    {
        $supplier = $this->suppliers->find($this->portalOrganizationId($request), $bookSupplierId) ?? abort(404);
        $this->suppliers->remove($supplier);

        return redirect()->route('portal.books.suppliers.index')->with('success', __('Supplier removed.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'name_ar' => ['nullable', 'string', 'max:200'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:100'],
            'subdivision' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
