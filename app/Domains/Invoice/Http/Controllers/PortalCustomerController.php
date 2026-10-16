<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Rules\VatNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The organization's customers, so an invoice starts with its buyer filled in.
 *
 * Anyone who writes invoices keeps them; a viewer reads (org.writer).
 */
class PortalCustomerController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Catalogue $catalogue,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $archived = $request->boolean('archived');

        return view('portal.customers.index', [
            'customers' => $this->catalogue->customers($this->organizationId($request), $search === '' ? null : $search, $archived, self::PER_PAGE)->withQueryString(),
            'search' => $search,
            'archived' => $archived,
        ]);
    }

    public function create(): View
    {
        return view('portal.customers.form', ['customer' => new Customer(['country_code' => 'SA'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organizationId = $this->organizationId($request);

        $this->catalogue->saveCustomer($organizationId, $this->validated($request, $organizationId));

        return redirect()->route('portal.customers.index')->with('success', __('Customer saved.'));
    }

    public function edit(Request $request, string $customerId): View
    {
        return view('portal.customers.form', ['customer' => $this->customer($request, $customerId)]);
    }

    public function update(Request $request, string $customerId): RedirectResponse
    {
        $organizationId = $this->organizationId($request);
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
        ], [
            'vat_number.unique' => __('Another customer already has this VAT number.'),
        ]);
    }

    private function customer(Request $request, string $customerId): Customer
    {
        return $this->catalogue->customer($this->organizationId($request), $customerId) ?? abort(404);
    }

    private function organizationId(Request $request): string
    {
        $organizationId = $request->attributes->get(PortalTenant::ORG_ID);

        abort_unless(is_string($organizationId), 404);

        return $organizationId;
    }
}
