<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Compliance\Fatoora\Services\SubmissionReport;
use App\Domains\Invoice\Services\InvoiceReport;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Services\TenantDashboard;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard API Controller.
 *
 * Provides usage statistics and health metrics for organizations.
 * Data is cached for performance (1-5 minute TTL based on metric type).
 *
 * The figures are the resolved tenant's, so an endpoint that caches or
 * certifies by organization refuses a caller that has not chosen one.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly TenantDashboard $dashboard,
        private readonly InvoiceReport $invoiceReport,
        private readonly SubmissionReport $submissionReport,
    ) {}

    /**
     * Get dashboard overview with all key metrics.
     *
     * GET /api/dashboard
     */
    public function index(): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();

        if ($organizationId === null) {
            return $this->withoutOrganization();
        }

        $data = Cache::remember(
            "dashboard:overview:{$organizationId}",
            60,
            fn () => $this->dashboard->overview($organizationId)
        );

        return ApiResponse::success($data);
    }

    /**
     * Get invoice statistics.
     *
     * GET /api/dashboard/invoices
     */
    public function invoices(): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();

        if ($organizationId === null) {
            return $this->withoutOrganization();
        }

        $data = Cache::remember(
            "dashboard:invoices:{$organizationId}",
            60,
            fn () => $this->invoiceReport->summary()
        );

        return ApiResponse::success($data);
    }

    /**
     * Get submission statistics.
     *
     * GET /api/dashboard/submissions
     */
    public function submissions(): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();

        if ($organizationId === null) {
            return $this->withoutOrganization();
        }

        $data = Cache::remember(
            "dashboard:submissions:{$organizationId}",
            60,
            fn () => $this->submissionReport->summary()
        );

        return ApiResponse::success($data);
    }

    /**
     * Get system health status.
     *
     * GET /api/dashboard/health
     *
     * Not cached: health is always read fresh.
     */
    public function health(): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();

        if ($organizationId === null) {
            return $this->withoutOrganization();
        }

        return ApiResponse::success($this->dashboard->health($organizationId));
    }

    /**
     * Get usage over time (for charts).
     *
     * GET /api/dashboard/usage?period=30d
     */
    public function usage(Request $request): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();

        if ($organizationId === null) {
            return $this->withoutOrganization();
        }

        $days = match ($request->query('period', '30d')) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 30,
        };

        $data = Cache::remember(
            "dashboard:usage:{$organizationId}:{$days}",
            300,
            fn () => $this->dashboard->usage($days)
        );

        return ApiResponse::success($data);
    }

    /**
     * Get real-time activity feed.
     *
     * GET /api/dashboard/activity?limit=20
     *
     * Not cached: activity is always read fresh.
     */
    public function activity(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 20), 100);

        $activities = $this->submissionReport->activity($limit);

        return ApiResponse::success([
            'activities' => $activities,
            'count' => $activities->count(),
        ]);
    }

    /**
     * A token with no organization claim has no tenant to report on.
     */
    private function withoutOrganization(): JsonResponse
    {
        return ApiResponse::error('Organization context is required.', 401);
    }
}
