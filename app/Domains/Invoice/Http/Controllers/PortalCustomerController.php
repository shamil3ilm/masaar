<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Contracts\LanguageRules;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Exceptions\RecordInUseException;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Services\CatalogueRemoval;
use App\Domains\Invoice\Services\CustomerHistory;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Rules\VatNumber;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use App\Support\CountryNames;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $this->catalogue->saveCustomer($organizationId, $this->validated($request, $organizationId));

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

        $this->catalogue->saveCustomer($organizationId, $this->validated($request, $organizationId, $customer), $customer);

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
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $organizationId, ?Customer $customer = null): array
    {
        $rules = $this->jurisdiction()->invoiceRules();

        // The country's buyer rules replace a shared one in its place, and
        // add their own after.
        return $request->validate([...[
            'name' => ['required', 'string', 'max:255'],
            // What an invoice naming the customer takes as the buyer's
            // Arabic name, required where the country requires Arabic.
            'name_ar' => $this->jurisdiction()->languageRules()->arabicNameRules(LanguageRules::CUSTOMER),
            'vat_number' => [
                'nullable', 'string', new VatNumber,
                Rule::unique('customers', 'vat_number')->where('org_id', $organizationId)->withoutTrashed()->ignore($customer?->id),
            ],
            'street' => ['nullable', 'string', 'max:255'],
            'building_number' => ['nullable', 'string', 'max:20'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            // A code from the list the form offers (ISO 3166-1 alpha-2).
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/', Rule::in(CountryNames::CODES)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            // Printed on the invoices that take them: in Arabic where the
            // country requires it.
            'payment_terms' => [...$this->jurisdiction()->languageRules()->arabicTextRules(), 'max:1000'],
        ], ...$rules->buyerRules($request)], [
            'vat_number.unique' => __('Another customer already has this VAT number.'),
            ...$rules->buyerMessages(),
        ]);
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
