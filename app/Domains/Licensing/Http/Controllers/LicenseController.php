<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Controllers;

use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Services\LicenseManagementService;
use App\Domains\Licensing\Services\UsageMeteringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * License Management Controller.
 *
 * API endpoints for license administration.
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
        $perPage = min((int) $request->get('per_page', 20), 100);

        $licenses = $this->managementService->listLicenses($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $licenses->items(),
            'meta' => [
                'current_page' => $licenses->currentPage(),
                'last_page' => $licenses->lastPage(),
                'per_page' => $licenses->perPage(),
                'total' => $licenses->total(),
            ],
        ]);
    }

    /**
     * Create a new license.
     *
     * POST /api/admin/licenses
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'organization_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'organization_vat' => 'sometimes|nullable|string|max:15',
            'contact_phone' => 'sometimes|nullable|string|max:20',
            'tier' => ['required', Rule::in(array_column(LicenseTier::cases(), 'value'))],
            'status' => ['sometimes', Rule::in(array_column(LicenseStatus::cases(), 'value'))],
            'max_invoices_per_month' => 'sometimes|integer|min:-1',
            'max_api_calls_per_day' => 'sometimes|integer|min:-1',
            'max_api_calls_per_minute' => 'sometimes|integer|min:1',
            'max_organizations' => 'sometimes|integer|min:-1',
            'features' => 'sometimes|array',
            'expires_at' => 'sometimes|nullable|date|after:now',
            'notes' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->managementService->createLicense($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'License created successfully',
            'data' => [
                'license' => $result['license']->toArray(),
                'credentials' => [
                    'api_key' => $result['api_key'],
                    'api_secret' => $result['api_secret'],
                ],
            ],
            'warning' => 'Store the API secret securely. It cannot be retrieved again.',
        ], 201);
    }

    /**
     * Get license details.
     *
     * GET /api/admin/licenses/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            $details = $this->managementService->getLicenseDetails($id);

            return response()->json([
                'success' => true,
                'data' => $details,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'LICENSE_NOT_FOUND',
                'message' => 'License not found',
            ], 404);
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

            return response()->json([
                'success' => true,
                'message' => 'License activated successfully',
                'data' => $license->toArray(),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'ACTIVATION_FAILED',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Suspend a license.
     *
     * POST /api/admin/licenses/{id}/suspend
     */
    public function suspend(Request $request, string $id): JsonResponse
    {
        $reason = $request->get('reason');
        $license = $this->managementService->suspendLicense($id, $reason);

        return response()->json([
            'success' => true,
            'message' => 'License suspended successfully',
            'data' => $license->toArray(),
        ]);
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

            return response()->json([
                'success' => true,
                'message' => 'License reactivated successfully',
                'data' => $license->toArray(),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'REACTIVATION_FAILED',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Revoke a license permanently.
     *
     * POST /api/admin/licenses/{id}/revoke
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $reason = $request->get('reason');
        $license = $this->managementService->revokeLicense($id, $reason);

        return response()->json([
            'success' => true,
            'message' => 'License revoked successfully',
            'data' => $license->toArray(),
        ]);
    }

    /**
     * Extend license expiration.
     *
     * POST /api/admin/licenses/{id}/extend
     */
    public function extend(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'required|integer|min:1|max:3650',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
                'errors' => $validator->errors(),
            ], 422);
        }

        $license = $this->managementService->extendLicense($id, $request->get('days'));

        return response()->json([
            'success' => true,
            'message' => 'License extended successfully',
            'data' => $license->toArray(),
        ]);
    }

    /**
     * Upgrade license tier.
     *
     * POST /api/admin/licenses/{id}/upgrade
     */
    public function upgrade(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tier' => ['required', Rule::in(array_column(LicenseTier::cases(), 'value'))],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
                'errors' => $validator->errors(),
            ], 422);
        }

        $license = $this->managementService->upgradeTier($id, $request->get('tier'));

        return response()->json([
            'success' => true,
            'message' => 'License tier upgraded successfully',
            'data' => $license->toArray(),
        ]);
    }

    /**
     * Update license limits.
     *
     * PATCH /api/admin/licenses/{id}/limits
     */
    public function updateLimits(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'max_invoices_per_month' => 'sometimes|integer|min:-1',
            'max_api_calls_per_day' => 'sometimes|integer|min:-1',
            'max_api_calls_per_minute' => 'sometimes|integer|min:1',
            'max_organizations' => 'sometimes|integer|min:-1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
                'errors' => $validator->errors(),
            ], 422);
        }

        $license = $this->managementService->updateLimits($id, $validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'License limits updated successfully',
            'data' => $license->toArray(),
        ]);
    }

    /**
     * Update license features.
     *
     * PATCH /api/admin/licenses/{id}/features
     */
    public function updateFeatures(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'features' => 'required|array',
            'features.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
                'errors' => $validator->errors(),
            ], 422);
        }

        $license = $this->managementService->updateFeatures($id, $request->get('features'));

        return response()->json([
            'success' => true,
            'message' => 'License features updated successfully',
            'data' => $license->toArray(),
        ]);
    }

    /**
     * Regenerate API secret.
     *
     * POST /api/admin/licenses/{id}/regenerate-secret
     */
    public function regenerateSecret(string $id): JsonResponse
    {
        $result = $this->managementService->regenerateSecret($id);

        return response()->json([
            'success' => true,
            'message' => 'API secret regenerated successfully',
            'data' => [
                'license' => $result['license']->toArray(),
                'api_secret' => $result['api_secret'],
            ],
            'warning' => 'Store the new API secret securely. It cannot be retrieved again.',
        ]);
    }

    /**
     * Get license usage summary.
     *
     * GET /api/admin/licenses/{id}/usage
     */
    public function usage(Request $request, string $id): JsonResponse
    {
        $license = \App\Domains\Licensing\Models\License::findOrFail($id);
        $month = $request->get('month', now()->format('Y-m'));

        $usage = $this->meteringService->getUsageSummary($license, $month);

        return response()->json([
            'success' => true,
            'data' => $usage,
        ]);
    }

    /**
     * Get license audit log.
     *
     * GET /api/admin/licenses/{id}/audit
     */
    public function audit(Request $request, string $id): JsonResponse
    {
        $limit = min((int) $request->get('limit', 50), 200);
        $logs = $this->managementService->getAuditLog($id, $limit);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * Get license statistics.
     *
     * GET /api/admin/licenses/statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = $this->managementService->getStatistics();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Cleanup expired rate limits.
     *
     * POST /api/admin/licenses/cleanup
     */
    public function cleanup(): JsonResponse
    {
        $deleted = $this->meteringService->cleanupExpiredRateLimits();

        return response()->json([
            'success' => true,
            'message' => 'Cleanup completed',
            'data' => [
                'rate_limits_deleted' => $deleted,
            ],
        ]);
    }
}
