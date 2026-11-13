<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Requests\SaveProfileRequest;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\SellerDetails;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The UAE seller details every document carries.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly SellerDetails $seller,
    ) {}

    /**
     * PUT /api/compliance/ae/profile
     */
    public function update(SaveProfileRequest $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $profile = $this->seller->save($organization, $request->validated());

        return ApiResponse::success([
            'organization' => $organization->fresh(),
            'settings' => $profile->settings,
        ], 'Seller details updated');
    }
}
