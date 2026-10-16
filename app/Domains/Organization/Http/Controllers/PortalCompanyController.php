<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Organization\DTOs\AddressFields;
use App\Domains\Organization\DTOs\OrganizationChangesData;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Services\Registrar;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\Services\UaeSellerDetails;
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
 */
class PortalCompanyController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Registrar $registrar,
        private readonly UaeSellerDetails $uae,
    ) {}

    public function edit(Request $request): View
    {
        $organization = $this->organization($request);

        return view('portal.company', [
            'organization' => $organization,
            'onboarded' => (bool) ($organization->compliance_profile['zatca_onboarded'] ?? false),
            // A UAE seller's tax identity is kept on its UAE compliance profile.
            'uae' => $organization->country === 'AE' ? $this->uae->current($organization) : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate(
            CompanyProfileRules::rules((string) $organization->country, complete: true, existing: $organization),
            CompanyProfileRules::messages(),
        );

        // Saved the way the API saves it, so the two cannot disagree about
        // where a TRN or an endpoint is kept.
        if ($organization->country === 'AE') {
            $this->uae->save($organization, $validated);

            return redirect()->route('portal.company.edit')->with('success', __('Company details saved.'));
        }

        $this->registrar->amend($organization, new OrganizationChangesData(
            name: $validated['name'],
            nameAr: $validated['name_ar'] ?? null,
            vatNumber: $validated['vat_number'],
            address: AddressFields::from($validated),
        ));

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

    private function organization(Request $request): Organization
    {
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can change its details.'));
    }
}
