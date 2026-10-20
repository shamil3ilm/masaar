<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Exceptions\RecordInUseException;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\CatalogueRemoval;
use App\Domains\Invoice\Services\CustomerHistory;
use App\Domains\Invoice\Support\CatalogueRules;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The organization's customers, so an invoice starts with its buyer filled in.
 *
 * Anyone who writes invoices keeps them; a viewer reads (org.writer). One an
 * invoice used is only archived; one never used can be removed, and brought
 * back from the list's Removed view (CatalogueRemoval).
 *
 * The buyer details every country keeps are checked here; what else a
 * customer keeps, and how its postal code is checked, are the
 * organization's country's (InvoiceRules::buyerRules(), buyerView()), so a
 * customer holds what that country's invoice form fills in.
 */
class PortalCustomerController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly CustomerHistory $history,
        private readonly Lists $lists,
        private readonly TenantResolver $tenant,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly CatalogueRemoval $removal,
        private readonly CatalogueRules $rules,
    ) {}

    public function index(Request $request): View
    {
        $list = $this->list($request, 'portal.customers.export');

        return view('portal.customers.index', [
            'customers' => $this->history->page($this->portalOrganizationId($request), $list),
            'list' => $list,
            'removed' => $list->value('archived') === CustomerHistory::REMOVED,
        ]);
    }

    /** Exactly the customers the list's filters give, as a CSV. */
    public function export(Request $request): StreamedResponse
    {
        return $this->history->csv($this->portalOrganizationId($request), $this->list($request), 'customers-'.now()->format('Y-m-d').'.csv');
    }

    public function create(): View
    {
        // A new customer is in the organization's own country until it says otherwise.
        return view('portal.customers.form', [
            'customer' => new Customer(['country_code' => $this->jurisdiction()->code()]),
            'buyerFields' => $this->jurisdiction()->invoiceRules()->buyerView(),
            'arabicRequired' => $this->jurisdiction()->languageRules()->requiresArabic(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);

        $this->catalogue->saveCustomer($organizationId, $this->validated($request));

        return redirect()->route('portal.customers.index')->with('success', __('Customer saved.'));
    }

    public function edit(Request $request, string $customerId): View
    {
        $customer = $this->customer($request, $customerId);

        return view('portal.customers.form', [
            'customer' => $customer,
            'removable' => ! $this->removal->inUse($customer),
            'buyerFields' => $this->jurisdiction()->invoiceRules()->buyerView(),
            'arabicRequired' => $this->jurisdiction()->languageRules()->requiresArabic(),
        ]);
    }

    public function update(Request $request, string $customerId): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);
        $customer = $this->customer($request, $customerId);

        $this->catalogue->saveCustomer($organizationId, $this->validated($request, $customer), $customer);

        return redirect()->route('portal.customers.index')->with('success', __('Customer saved.'));
    }

    public function archive(Request $request, string $customerId): RedirectResponse
    {
        $customer = $this->customer($request, $customerId);
        $archived = ! $request->boolean('restore');

        $this->catalogue->archive($customer, $archived);

        return redirect()->route('portal.customers.index')
            ->with('success', $archived ? __(':name archived. Invoices already written keep their details.', ['name' => $customer->name]) : __(':name restored.', ['name' => $customer->name]));
    }

    /** Remove a customer no invoice has used; one that was used is archived instead. */
    public function remove(Request $request, string $customerId): RedirectResponse
    {
        $customer = $this->customer($request, $customerId);

        try {
            $this->removal->remove($customer);
        } catch (RecordInUseException $e) {
            return redirect()->route('portal.customers.edit', $customerId)->with('error', $e->getMessage());
        }

        return redirect()->route('portal.customers.index')
            ->with('success', __(':name removed. Show the removed ones in the list to bring it back.', ['name' => $customer->name]));
    }

    /** Bring back a removed customer, from the list's Removed view. */
    public function restoreRemoved(Request $request, string $customerId): RedirectResponse
    {
        $customer = $this->removal->removedCustomer($this->portalOrganizationId($request), $customerId) ?? abort(404);

        try {
            $this->removal->restore($customer);
        } catch (DomainException $e) {
            return redirect()->route('portal.customers.index', ['archived' => CustomerHistory::REMOVED])->with('error', $e->getMessage());
        }

        return redirect()->route('portal.customers.index')->with('success', __(':name restored.', ['name' => $customer->name]));
    }

    /**
     * As CatalogueRules has them for the organization's country: the rules
     * the CSV import checks a row by too.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Customer $customer = null): array
    {
        [$rules, $messages] = $this->rules->customer($this->tenant->getOrganization(), $request, $customer);

        return $request->validate($rules, $messages);
    }

    /** The organization's country's module, switched on or not. */
    private function jurisdiction(): Jurisdiction
    {
        return $this->jurisdictions->forRules($this->tenant->getOrganization());
    }

    private function customer(Request $request, string $customerId): Customer
    {
        return $this->catalogue->customer($this->portalOrganizationId($request), $customerId) ?? abort(404);
    }

    private function list(Request $request, ?string $export = null): ListState
    {
        $organization = $this->portalOrganization($this->tenant, $request);

        return $this->lists->portal($this->history->spec($organization, 'portal.customers.index'), $request, (string) $organization->id, $export);
    }
}
