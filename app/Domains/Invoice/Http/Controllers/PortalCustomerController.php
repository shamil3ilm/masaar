<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Contracts\LanguageRules;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Rules\VatNumber;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The organization's customers, so an invoice starts with its buyer filled in.
 *
 * Anyone who writes invoices keeps them; a viewer reads (org.writer).
 *
 * The buyer details every country keeps are checked here; what else a
 * customer keeps, and how its postal code is checked, are the
 * organization's country's (InvoiceRules::buyerRules(), buyerView()), so a
 * customer holds what that country's invoice form fills in.
 */
class PortalCustomerController extends Controller
{
    use ResolvesPortalOrganization;

    private const PER_PAGE = 25;

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly TenantResolver $tenant,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $archived = $request->boolean('archived');

        return view('portal.customers.index', [
            'customers' => $this->catalogue->customers($this->portalOrganizationId($request), $search === '' ? null : $search, $archived, self::PER_PAGE)->withQueryString(),
            'search' => $search,
            'archived' => $archived,
        ]);
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
        return view('portal.customers.form', [
            'customer' => $this->customer($request, $customerId),
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
                Rule::unique('customers', 'vat_number')->where('org_id', $organizationId)->ignore($customer?->id),
            ],
            'street' => ['nullable', 'string', 'max:255'],
            'building_number' => ['nullable', 'string', 'max:20'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'payment_terms' => ['nullable', 'string', 'max:1000'],
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
}
