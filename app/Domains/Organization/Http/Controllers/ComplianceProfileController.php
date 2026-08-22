<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComplianceProfileController extends Controller
{
    /**
     * GET /api/organizations/{organization}/compliance-profiles
     */
    public function index(Organization $organization): JsonResponse
    {
        $profiles = $organization->complianceProfiles()->get();

        return ApiResponse::success($profiles->toArray());
    }

    /**
     * POST /api/organizations/{organization}/compliance-profiles
     */
    public function store(Request $request, Organization $organization): JsonResponse
    {
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
            'settings' => ['sometimes', 'array'],
        ]);

        $profile = $organization->complianceProfiles()->create($validated);

        return ApiResponse::success($profile->toArray(), 'Compliance profile created', 201);
    }

    /**
     * DELETE /api/organizations/{organization}/compliance-profiles/{profile}
     */
    public function destroy(Organization $organization, ComplianceProfile $profile): JsonResponse
    {
        abort_if(
            $profile->org_id !== $organization->id,
            403,
            'Profile does not belong to this organization'
        );

        $profile->delete();

        return ApiResponse::success(null, 'Compliance profile deleted');
    }
}
