<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Compliance\Engine\Contracts\SellerRules;
use App\Domains\Organization\DTOs\OrganizationChangesData;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Services\Registrar;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The seller details printed on every invoice and named in its certificate.
 *
 * ZATCA onboarding refuses an organization until its VAT number and address
 * are complete, and there was no screen to complete them on - only an API
 * that accepted a name and a VAT number. Its administrators only; the
 * organization is the one the session works in.
 *
 * The form, its rules and where the details are kept are the organization's
 * country's (its module's SellerRules).
 */
class PortalCompanyController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Registrar $registrar,
    ) {}

    public function edit(Request $request): View
    {
        $organization = $this->organization($request);
        $seller = $this->seller($organization);

        return view('portal.company', [
            'organization' => $organization,
            'form' => $seller->companyView(),
            'formData' => $seller->companyData($organization),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate(
            CompanyProfileRules::rules($organization->country, complete: true, existing: $organization),
            CompanyProfileRules::messages($organization->country),
        );

        // Saved the way the API saves it, so the two cannot disagree about
        // where a detail is kept.
        $this->seller($organization)->save($organization, $validated);

        return redirect()->route('portal.company.edit')->with('success', __('Company details saved.'));
    }

    /**
     * Require everyone in the organization to sign in with a second factor.
     *
     * Turning it on needs the administrator to have one already, or the next
     * page they open would send them away to set it up mid-change.
     */
    public function security(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $required = $request->boolean('require_two_factor');

        if ($required && ! $request->user()->hasTwoFactor()) {
            return redirect()->route('portal.company.edit')
                ->with('error', __('Turn on two-factor for your own account first, on My account.'));
        }

        $this->registrar->requireTwoFactor($organization, $required);

        return redirect()->route('portal.company.edit')->with('success', $required
            ? __('Two-factor is now required. Members without it are asked to set it up when they next open the portal.')
            : __('Two-factor is no longer required.'));
    }

    /**
     * Whether the company's prices include VAT: what an invoice that does
     * not say is priced by. The API could always set it; the portal could
     * not, so a shop pricing with VAT in had every invoice add it on top.
     */
    public function pricing(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $validated = $request->validate(['prices_include_vat' => ['required', 'boolean']]);

        $this->registrar->amend($organization, new OrganizationChangesData(
            pricesIncludeVat: (bool) $validated['prices_include_vat'],
        ));

        return redirect()->route('portal.company.edit')->with('success', __('Pricing saved. New invoices follow it unless they say otherwise.'));
    }

    private function seller(Organization $organization): SellerRules
    {
        return CompanyProfileRules::jurisdiction($organization->country)->sellerRules();
    }

    private function organization(Request $request): Organization
    {
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can change its details.'));
    }
}
