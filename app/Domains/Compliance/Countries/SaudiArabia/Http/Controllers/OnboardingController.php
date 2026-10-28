<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ZATCA onboarding over the API: OTP, compliance checks, production CSID.
 *
 * The steps live in OrganizationOnboarding, which the portal's wizard calls
 * too; this answers in the API's terms.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly OrganizationOnboarding $onboarding,
    ) {}

    /**
     * Step 1: Request Compliance CSID.
     *
     * POST /api/compliance/onboarding/ccsid
     *
     * Requires OTP from ZATCA portal (valid for 1 hour).
     */
    public function requestCcsid(Request $request): JsonResponse
    {
        $device = $request->validate([
            'otp' => ['required', 'string', 'size:6'],
            'common_name' => ['required', 'string', 'max:255'],
            'serial_number' => ['required', 'string', 'max:50'],
            'organization_unit' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            // A renewal is refused while documents the current certificate
            // signed are unfiled; a reason renews anyway, and is audited.
            'renewal_override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->onboarding->requestCcsid($this->tenant->getOrganization(), (string) $device['otp'], $device, $device['renewal_override_reason'] ?? null);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (CertificateException $e) {
            // Its own code and category, from the one place that knows them.
            return $e->apiResponse();
        }

        return ApiResponse::success([
            'ccsid' => substr($result['ccsid'], 0, 50).'...', // Truncate for display
            'request_id' => $result['request_id'],
        ], 'Compliance CSID obtained. Proceed with compliance checks.');
    }

    /**
     * Step 2: Run compliance checks.
     *
     * POST /api/compliance/onboarding/compliance-check
     */
    public function runComplianceCheck(Request $request): JsonResponse
    {
        try {
            $result = $this->onboarding->runComplianceCheck($this->tenant->getOrganization());
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (CertificateException $e) {
            return $e->apiResponse();
        }

        return ApiResponse::success($result, $result['passed']
            ? 'Compliance checks passed. Proceed to request PCSID.'
            : 'Compliance checks failed. Fix errors and retry.');
    }

    /**
     * Step 3: Request Production CSID.
     *
     * POST /api/compliance/onboarding/pcsid
     */
    public function requestPcsid(Request $request): JsonResponse
    {
        $override = $request->validate(['renewal_override_reason' => ['nullable', 'string', 'max:500']]);

        try {
            $this->onboarding->requestPcsid($this->tenant->getOrganization(), $override['renewal_override_reason'] ?? null);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (CertificateException $e) {
            return $e->apiResponse();
        }

        return ApiResponse::success(
            ['onboarded' => true],
            'Production CSID obtained. Organization is now ZATCA compliant.',
        );
    }

    /**
     * Get onboarding status.
     *
     * GET /api/compliance/onboarding/status
     */
    public function status(): JsonResponse
    {
        return ApiResponse::success($this->onboarding->status($this->tenant->getOrganization()));
    }
}
