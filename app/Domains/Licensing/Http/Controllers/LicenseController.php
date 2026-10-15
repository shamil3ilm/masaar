<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Controllers;

use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Exceptions\LicenceStateException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseManagementService;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Http\Pagination\PageSize;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * License Management Controller.
 *
 * API endpoints for license administration.
 *
 * Every answer is the envelope the rest of the API uses. This controller built
 * its own - {success, error_code, errors} - so its validation failures and
 * refusals read differently from every other endpoint's, and the two
 * lifecycle actions caught any RuntimeException and returned its message,
 * which a database failure would have filled with SQL. Validation now goes
 * through the shared renderer, and only a licence's own refusal
 * (LicenceStateException) is answered with its words.
 */
class LicenseController extends Controller
{
    public function __construct(
        private readonly LicenseManagementService $managementService,
        private readonly UsageMeteringService $meteringService,
    ) {}

    /**
     * List all licenses.
     *
     * GET /api/admin/licenses
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'tier', 'search', 'expired']);
        $perPage = PageSize::fromRequest($request, 20);

        return ApiResponse::paginated($this->managementService->listLicenses($filters, $perPage));
    }

    /**
     * Create a new license.
     *
     * POST /api/admin/licenses
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // The organization every partner call on this licence acts for.
            'org_id' => 'required|uuid|exists:organizations,id',
            'organization_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'organization_vat' => 'sometimes|nullable|string|max:15',
            'contact_phone' => 'sometimes|nullable|string|max:20',
            'tier' => ['required', Rule::in(array_column(LicenseTier::cases(), 'value'))],
            'status' => ['sometimes', Rule::in(array_column(LicenseStatus::cases(), 'value'))],
            'invoices_per_month' => 'sometimes|integer|min:-1|max:4294967295',
            'calls_per_day' => 'sometimes|integer|min:-1|max:4294967295',
            'calls_per_min' => 'sometimes|integer|min:1',
            'max_organizations' => 'sometimes|integer|min:-1|max:4294967295',
            'features' => 'sometimes|array',
            'expires_at' => 'sometimes|nullable|date|after:now',
            'notes' => 'sometimes|nullable|string',
        ]);

        $result = $this->managementService->createLicense($validated);

        return ApiResponse::created([
            'license' => $result['license']->toArray(),
            'credentials' => [
                'api_key' => $result['api_key'],
                'api_secret' => $result['api_secret'],
            ],
        ], 'License created. Store the API secret securely: it cannot be retrieved again.');
    }

    /**
     * Get license details.
     *
     * GET /api/admin/licenses/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            return ApiResponse::success($this->managementService->getLicenseDetails($id));
        } catch (ModelNotFoundException) {
            return ApiResponse::error('License not found', 404, [], 'LICENSE_NOT_FOUND');
        }
    }

    /**
     * Activate a pending license.
     *
     * POST /api/admin/licenses/{id}/activate
     */
    public function activate(string $id): JsonResponse
    {
        try {
            $license = $this->managementService->activateLicense($id);
        } catch (LicenceStateException $e) {
            return ApiResponse::error($e->getMessage(), 409, [], 'ACTIVATION_FAILED');
        }

        return ApiResponse::success($license->toArray(), 'License activated');
    }

    /**
     * Suspend a license.
     *
     * POST /api/admin/licenses/{id}/suspend
     */
    public function suspend(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => 'sometimes|nullable|string|max:255'])['reason'] ?? null;

        try {
            $license = $this->managementService->suspendLicense($id, $reason);
        } catch (LicenceStateException $e) {
            return ApiResponse::error($e->getMessage(), 409, [], 'SUSPENSION_FAILED');
        }

        return ApiResponse::success($license->toArray(), 'License suspended');
    }

    /**
     * Reactivate a suspended license.
     *
     * POST /api/admin/licenses/{id}/reactivate
     */
    public function reactivate(string $id): JsonResponse
    {
        try {
            $license = $this->managementService->reactivateLicense($id);
        } catch (LicenceStateException $e) {
            return ApiResponse::error($e->getMessage(), 409, [], 'REACTIVATION_FAILED');
        }

        return ApiResponse::success($license->toArray(), 'License reactivated');
    }

    /**
     * Revoke a license permanently.
     *
     * POST /api/admin/licenses/{id}/revoke
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => 'sometimes|nullable|string|max:255'])['reason'] ?? null;

        return ApiResponse::success($this->managementService->revokeLicense($id, $reason)->toArray(), 'License revoked');
    }

    /**
     * Extend license expiration.
     *
     * POST /api/admin/licenses/{id}/extend
     */
    public function extend(Request $request, string $id): JsonResponse
    {
        $days = (int) $request->validate(['days' => 'required|integer|min:1|max:3650'])['days'];

        return ApiResponse::success($this->managementService->extendLicense($id, $days)->toArray(), 'License extended');
    }

    /**
     * Upgrade license tier.
     *
     * POST /api/admin/licenses/{id}/upgrade
     */
    public function upgrade(Request $request, string $id): JsonResponse
    {
        $tier = $request->validate([
            'tier' => ['required', Rule::in(array_column(LicenseTier::cases(), 'value'))],
        ])['tier'];

        return ApiResponse::success($this->managementService->upgradeTier($id, $tier)->toArray(), 'License tier upgraded');
    }

    /**
     * Update license limits.
     *
     * PATCH /api/admin/licenses/{id}/limits
     */
    public function updateLimits(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'invoices_per_month' => 'sometimes|integer|min:-1|max:4294967295',
            'calls_per_day' => 'sometimes|integer|min:-1|max:4294967295',
            'calls_per_min' => 'sometimes|integer|min:1',
            'max_organizations' => 'sometimes|integer|min:-1|max:4294967295',
        ]);

        return ApiResponse::success($this->managementService->updateLimits($id, $validated)->toArray(), 'License limits updated');
    }

    /**
     * Update license features.
     *
     * PATCH /api/admin/licenses/{id}/features
     */
    public function updateFeatures(Request $request, string $id): JsonResponse
    {
        $features = $request->validate([
            'features' => 'required|array',
            'features.*' => 'string',
        ])['features'];

        return ApiResponse::success($this->managementService->updateFeatures($id, $features)->toArray(), 'License features updated');
    }

    /**
     * Regenerate API secret.
     *
     * POST /api/admin/licenses/{id}/regenerate-secret
     */
    public function regenerateSecret(string $id): JsonResponse
    {
        $result = $this->managementService->regenerateSecret($id);

        return ApiResponse::success([
            'license' => $result['license']->toArray(),
            'api_secret' => $result['api_secret'],
        ], 'API secret regenerated. Store it securely: it cannot be retrieved again.');
    }

    /**
     * Get license usage summary.
     *
     * GET /api/admin/licenses/{id}/usage
     */
    public function usage(Request $request, string $id): JsonResponse
    {
        $license = License::findOrFail($id);

        // Unvalidated, month[]=x reached a string parameter under strict
        // types and answered 500, as it did on the partner's own endpoint.
        $month = $request->validate([
            'month' => ['sometimes', 'string', 'date_format:Y-m'],
        ])['month'] ?? now()->format('Y-m');

        return ApiResponse::success($this->meteringService->getUsageSummary($license, $month));
    }

    /**
     * Get license audit log.
     *
     * GET /api/admin/licenses/{id}/audit
     */
    public function audit(Request $request, string $id): JsonResponse
    {
        $limit = PageSize::fromRequest($request, 50, 200, 'limit');

        return ApiResponse::success($this->managementService->getAuditLog($id, $limit));
    }

    /**
     * Get license statistics.
     *
     * GET /api/admin/licenses/statistics
     */
    public function statistics(): JsonResponse
    {
        return ApiResponse::success($this->managementService->getStatistics());
    }

    /**
     * Cleanup expired rate limits.
     *
     * POST /api/admin/licenses/cleanup
     */
    public function cleanup(): JsonResponse
    {
        return ApiResponse::success([
            'rate_limits_deleted' => $this->meteringService->cleanupExpiredRateLimits(),
        ], 'Cleanup completed');
    }
}
