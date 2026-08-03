<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Http\Controllers;

use App\Domains\Compliance\Fatoora\Services\EnvironmentVarianceTracker;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Environment Variance API Controller.
 *
 * Provides API access to sandbox vs production variance tracking.
 * Helps customers understand why invoices fail in production
 * when they passed in sandbox.
 *
 * @see docs/COMPLIANCE-POLICIES.md Section 9: Sandbox vs Production Variance
 */
class VarianceController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly EnvironmentVarianceTracker $varianceTracker,
    ) {}

    /**
     * List variances for the organization.
     *
     * GET /api/compliance/variances
     *
     * Query params:
     * - type: Filter by variance type (sandbox_only_pass, validation_difference, etc.)
     * - limit: Max results (default 50)
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();
        $type = $request->query('type');
        $limit = min((int) $request->query('limit', 50), 100);

        $variances = $this->varianceTracker->getOrganizationVariances(
            $organizationId,
            $type,
            $limit
        );

        return ApiResponse::success([
            'variances' => $variances,
            'count' => count($variances),
            'filters' => [
                'type' => $type,
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Get a specific variance by ID.
     *
     * GET /api/compliance/variances/{id}
     */
    public function show(string $id): JsonResponse
    {
        $variance = $this->varianceTracker->getVariance($id);

        if (!$variance) {
            return ApiResponse::notFound('Variance not found');
        }

        // Verify organization ownership
        if ($variance->organization_id !== $this->tenant->getOrganizationId()) {
            return ApiResponse::forbidden('You do not have access to this variance');
        }

        return ApiResponse::success($this->varianceTracker->generateVarianceReport($id));
    }

    /**
     * Get variance statistics.
     *
     * GET /api/compliance/variances/statistics
     */
    public function statistics(): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();
        $stats = $this->varianceTracker->getStatistics($organizationId);

        return ApiResponse::success($stats);
    }

    /**
     * Mark a variance as reported to ZATCA.
     *
     * POST /api/compliance/variances/{id}/report
     *
     * Body:
     * - ticket_id: ZATCA support ticket ID
     */
    public function markReported(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'ticket_id' => 'required|string|max:100',
        ]);

        $variance = $this->varianceTracker->getVariance($id);

        if (!$variance) {
            return ApiResponse::notFound('Variance not found');
        }

        if ($variance->organization_id !== $this->tenant->getOrganizationId()) {
            return ApiResponse::forbidden('You do not have access to this variance');
        }

        if ($variance->reported_to_zatca) {
            return ApiResponse::error('Variance is already reported to ZATCA', 422);
        }

        try {
            $this->varianceTracker->markReportedToZatca($id, $request->input('ticket_id'));

            return ApiResponse::success([
                'variance_id' => $id,
                'ticket_id' => $request->input('ticket_id'),
                'status' => 'reported',
            ], 'Variance marked as reported to ZATCA');
        } catch (\RuntimeException $e) {
            return ApiResponse::error('Failed to update variance: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Resolve a variance.
     *
     * POST /api/compliance/variances/{id}/resolve
     *
     * Body:
     * - status: Resolution status (resolved, wont_fix)
     * - notes: Optional resolution notes
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:resolved,wont_fix',
            'notes' => 'nullable|string|max:1000',
        ]);

        $variance = $this->varianceTracker->getVariance($id);

        if (!$variance) {
            return ApiResponse::notFound('Variance not found');
        }

        if ($variance->organization_id !== $this->tenant->getOrganizationId()) {
            return ApiResponse::forbidden('You do not have access to this variance');
        }

        $this->varianceTracker->resolveVariance(
            $id,
            $request->input('status'),
            $request->input('notes')
        );

        return ApiResponse::success([
            'variance_id' => $id,
            'status' => $request->input('status'),
        ], 'Variance resolved');
    }
}
