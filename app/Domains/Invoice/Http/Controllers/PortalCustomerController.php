<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal;
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
 * A UAE organization's customers also keep the two buyer details its
 * invoice form fills in: the Peppol endpoint the buyer's service provider
 * issued, and the emirate. A Saudi organization's are as they were.
 */
class PortalCustomerController extends Controller
{
    use ResolvesPortalOrganization;

    private const PER_PAGE = 25;

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly TenantResolver $tenant,
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
        $uae = $this->uae();

        return view('portal.customers.form', ['customer' => new Customer(['country_code' => $uae ? 'AE' : 'SA']), 'uae' => $uae]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);

        $this->catalogue->saveCustomer($organizationId, $this->validated($request, $organizationId));

        return redirect()->route('portal.customers.index')->with('success', __('Customer saved.'));
    }

    public function edit(Request $request, string $customerId): View
    {
        return view('portal.customers.form', ['customer' => $this->customer($request, $customerId), 'uae' => $this->uae()]);
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
        $saudi = strtoupper((string) $request->input('country_code', 'SA')) === 'SA';

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'vat_number' => [
                'nullable', 'string', new VatNumber,
                Rule::unique('customers', 'vat_number')->where('org_id', $organizationId)->ignore($customer?->id),
            ],
            'street' => ['nullable', 'string', 'max:255'],
            'building_number' => ['nullable', 'string', 'max:20'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            // BR-KSA-67, as the invoice checks it.
            'postal_code' => $saudi ? ['nullable', 'digits:5'] : ['nullable', 'string', 'max:10'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            ...($this->uae() ? self::uaeRules($request) : []),
        ], [
            'vat_number.unique' => __('Another customer already has this VAT number.'),
            'peppol_endpoint.regex' => __('The Peppol endpoint is :scheme: followed by 10 digits, as the service provider issued it.', ['scheme' => PintAe::SCHEME]),
            'subdivision.in' => __('The emirate is one of :codes.', ['codes' => implode(', ', PintAe::EMIRATES)]),
        ]);
    }

    /**
     * The endpoint as the service provider issued it - 0235: and ten digits,
     * which the three reserved addresses also are - and, for a buyer in the
     * UAE, the emirate code the invoice form takes.
     *
     * @return array<string, list<mixed>>
     */
    private static function uaeRules(Request $request): array
    {
        $inUae = strtoupper((string) $request->input('country_code', 'AE')) === 'AE';

        return [
            'peppol_endpoint' => ['nullable', 'string', 'regex:'.Customer::UAE_ENDPOINT],
            'subdivision' => ['nullable', 'string', 'max:50', ...($inUae ? [Rule::in(PintAe::EMIRATES)] : [])],
        ];
    }

    private function uae(): bool
    {
        return UaePortal::offers($this->tenant->getOrganization());
    }

    private function customer(Request $request, string $customerId): Customer
    {
        return $this->catalogue->customer($this->portalOrganizationId($request), $customerId) ?? abort(404);
    }
}
