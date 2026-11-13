<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Books\Http\Requests\SaveBookSupplierRequest;
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

    public function store(SaveBookSupplierRequest $request): RedirectResponse
    {
        $this->suppliers->save($this->portalOrganizationId($request), $request->validated());

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

    public function update(SaveBookSupplierRequest $request, string $bookSupplierId): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);
        $supplier = $this->suppliers->find($organizationId, $bookSupplierId) ?? abort(404);
        $this->suppliers->save($organizationId, $request->validated(), $supplier);

        return redirect()->route('portal.books.suppliers.index')->with('success', __('Supplier saved.'));
    }

    public function destroy(Request $request, string $bookSupplierId): RedirectResponse
    {
        $supplier = $this->suppliers->find($this->portalOrganizationId($request), $bookSupplierId) ?? abort(404);
        $this->suppliers->remove($supplier);

        return redirect()->route('portal.books.suppliers.index')->with('success', __('Supplier removed.'));
    }
}
