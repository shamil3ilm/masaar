<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\VatNumber;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An organization's compliance profiles, for that organization only.
 *
 * Nothing compared {organization} with the caller's, and Organization has no
 * tenant scope, so any token - one from /api/auth/register would do - read
 * and created profiles in any organization. A profile's vat_number overrides
 * the organization's on every invoice it signs, and a suspended or revoked
 * profile stops all its filing. Every action now refuses an organization other
 * than the caller's current one, as OrganizationController::update does, and
 * answers 404 so the route does not confirm that another one exists.
 */
class ComplianceProfileController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
    ) {}

    /**
     * GET /api/organizations/{organization}/compliance-profiles
     */
    public function index(Organization $organization): JsonResponse
    {
        $this->ownOrganization($organization);

        $profiles = $organization->complianceProfiles()->get();

        return ApiResponse::success($profiles->toArray());
    }

    /**
     * POST /api/organizations/{organization}/compliance-profiles
     *
     * Administrators only (org.admin on the route): a profile sets the seller
     * VAT number and can stop filing, and changing the VAT number through the
     * organization is already an administrator's call.
     */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->ownOrganization($organization);

        $validated = $request->validate([
            'jurisdiction' => ['required', 'string', 'size:2'],
            'engine' => ['required', 'string', 'max:32'],
            // Any string was accepted, so a profile could be created in a
            // state nothing recognises — neither active nor stopped, and
            // therefore silently treated as merely waiting.
            'status' => ['sometimes', Rule::in([
                ComplianceProfile::STATUS_PENDING,
                ComplianceProfile::STATUS_ACTIVE,
                ComplianceProfile::STATUS_SUSPENDED,
                ComplianceProfile::STATUS_REVOKED,
            ])],
            // Only the settings something reads, validated as the
            // organization's own would be. Any array was stored, including a
            // VAT number the organization's rule would have refused.
            'settings' => ['sometimes', 'array:vat_number'],
            'settings.vat_number' => ['nullable', 'string', new VatNumber],
        ]);

        $profile = $organization->complianceProfiles()->create($validated);

        return ApiResponse::success($profile->toArray(), 'Compliance profile created', 201);
    }

    /**
     * DELETE /api/organizations/{organization}/compliance-profiles/{profile}
     *
     * The profile is found through the organization rather than bound from the
     * route: route bindings resolve before JwtGuard sets the tenant, so a bound
     * ComplianceProfile was looked up with no tenant and was never found.
     */
    public function destroy(Organization $organization, string $profile): JsonResponse
    {
        $this->ownOrganization($organization);

        $organization->complianceProfiles()->findOrFail($profile)->delete();

        return ApiResponse::success(null, 'Compliance profile deleted');
    }

    private function ownOrganization(Organization $organization): void
    {
        abort_if($organization->id !== $this->tenant->getOrganizationId(), 404);
    }
}
