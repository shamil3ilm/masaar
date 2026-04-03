<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Compliance\Fatoora\Services\ClusterCircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\EnvironmentVarianceTracker;
use App\Domains\Compliance\Fatoora\Services\CertificateLineageService;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard API Controller.
 *
 * Provides usage statistics and health metrics for organizations.
 * Data is cached for performance (1-5 minute TTL based on metric type).
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly ClusterCircuitBreaker $circuitBreaker,
        private readonly EnvironmentVarianceTracker $varianceTracker,
        private readonly CertificateLineageService $certificateService,
    ) {}

    /**
     * Get dashboard overview with all key metrics.
     *
     * GET /api/dashboard
     */
    public function index(): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();
        $cacheKey = "dashboard:overview:{$organizationId}";

        $data = Cache::remember($cacheKey, 60, function () use ($organizationId) {
            return [
                'invoices' => $this->getInvoiceStats($organizationId),
                'submissions' => $this->getSubmissionStats($organizationId),
                'compliance' => $this->getComplianceStats($organizationId),
                'certificates' => $this->getCertificateStats($organizationId),
                'generated_at' => now()->toIso8601String(),
            ];
        });

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
        $cacheKey = "dashboard:invoices:{$organizationId}";

        $data = Cache::remember($cacheKey, 60, function () use ($organizationId) {
            return $this->getInvoiceStats($organizationId);
        });

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
        $cacheKey = "dashboard:submissions:{$organizationId}";

        $data = Cache::remember($cacheKey, 60, function () use ($organizationId) {
            return $this->getSubmissionStats($organizationId);
        });

        return ApiResponse::success($data);
    }

    /**
     * Get system health status.
     *
     * GET /api/dashboard/health
     */
    public function health(): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();

        // Health data is not cached - always fresh
        $data = [
            'circuit_breaker' => $this->circuitBreaker->getMetrics('zatca_api'),
            'queue' => $this->getQueueHealth($organizationId),
            'certificates' => $this->getCertificateHealth($organizationId),
            'variances' => $this->varianceTracker->getStatistics($organizationId),
            'checked_at' => now()->toIso8601String(),
        ];

        // Determine overall health status
        $data['status'] = $this->determineOverallHealth($data);

        return ApiResponse::success($data);
    }

    /**
     * Get usage over time (for charts).
     *
     * GET /api/dashboard/usage?period=30d
     */
    public function usage(Request $request): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();
        $period = $request->query('period', '30d');

        $days = match ($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 30,
        };

        $cacheKey = "dashboard:usage:{$organizationId}:{$days}";

        $data = Cache::remember($cacheKey, 300, function () use ($organizationId, $days) {
            return $this->getUsageOverTime($organizationId, $days);
        });

        return ApiResponse::success($data);
    }

    /**
     * Get real-time activity feed.
     *
     * GET /api/dashboard/activity?limit=20
     */
    public function activity(Request $request): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();
        $limit = min((int) $request->query('limit', 20), 100);

        // Activity is not cached - always fresh
        $activities = DB::table('invoice_submissions')
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->select([
                'id',
                'invoice_id',
                'state',
                'reporting_status',
                'created_at',
                'updated_at',
            ])
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'invoice_id' => $s->invoice_id,
                'status' => $s->state ?? $s->reporting_status ?? 'pending',
                'timestamp' => $s->updated_at ?? $s->created_at,
            ]);

        return ApiResponse::success([
            'activities' => $activities,
            'count' => $activities->count(),
        ]);
    }

    /**
     * Get invoice statistics.
     * Optimized: Single query with conditional aggregates instead of 6 separate queries.
     */
    private function getInvoiceStats(string $organizationId): array
    {
        $today = now()->startOfDay();
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        // Single query for all counts and sum
        $stats = DB::table('invoices')
            ->where('organization_id', $organizationId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as this_month,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as last_month,
                COALESCE(SUM(total), 0) as total_amount
            ", [$today, $thisMonth, $lastMonth, $thisMonth])
            ->first();

        // Separate query for by_type (GROUP BY needed)
        $byType = DB::table('invoices')
            ->where('organization_id', $organizationId)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return [
            'total' => (int) ($stats->total ?? 0),
            'today' => (int) ($stats->today ?? 0),
            'this_month' => (int) ($stats->this_month ?? 0),
            'last_month' => (int) ($stats->last_month ?? 0),
            'by_type' => $byType,
            'total_amount' => (float) ($stats->total_amount ?? 0),
        ];
    }

    /**
     * Get submission statistics.
     * Optimized: Single query with conditional aggregates instead of 6 separate queries.
     */
    private function getSubmissionStats(string $organizationId): array
    {
        $stats = DB::table('invoice_submissions')
            ->where('organization_id', $organizationId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN state = 'cleared' THEN 1 ELSE 0 END) as cleared,
                SUM(CASE WHEN state = 'reported' THEN 1 ELSE 0 END) as reported,
                SUM(CASE WHEN state = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN state IN ('pending_submission', 'submitted', 'queued') THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        $total = (int) ($stats->total ?? 0);
        $cleared = (int) ($stats->cleared ?? 0);
        $reported = (int) ($stats->reported ?? 0);
        $rejected = (int) ($stats->rejected ?? 0);

        // Calculate success rate inline
        $completed = $cleared + $reported + $rejected;
        $successRate = $completed > 0 ? round((($cleared + $reported) / $completed) * 100, 2) : 100.0;

        return [
            'total' => $total,
            'cleared' => $cleared,
            'reported' => $reported,
            'rejected' => $rejected,
            'pending' => (int) ($stats->pending ?? 0),
            'success_rate' => $successRate,
        ];
    }

    /**
     * Get compliance statistics.
     */
    private function getComplianceStats(string $organizationId): array
    {
        $variances = $this->varianceTracker->getStatistics($organizationId);

        return [
            'hash_chain_intact' => $this->isHashChainIntact($organizationId),
            'latest_icv' => DB::table('hash_chain_state')
                ->where('organization_id', $organizationId)
                ->value('last_icv') ?? 0,
            'variances' => $variances,
        ];
    }

    /**
     * Get certificate statistics.
     */
    private function getCertificateStats(string $organizationId): array
    {
        $activeCert = $this->certificateService->getActiveCertificate($organizationId);
        $history = $this->certificateService->getCertificateHistory($organizationId);

        $daysUntilExpiry = null;
        if ($activeCert && isset($activeCert['valid_to'])) {
            $expiryDate = new \DateTimeImmutable($activeCert['valid_to']);
            $daysUntilExpiry = max(0, (int) $expiryDate->diff(now())->format('%r%a'));
        }

        return [
            'active' => $activeCert !== null,
            'days_until_expiry' => $daysUntilExpiry,
            'total_certificates' => count($history),
            'invoices_signed' => $activeCert
                ? $this->certificateService->getInvoiceCountForCertificate($activeCert['certificate_id'])
                : 0,
        ];
    }

    /**
     * Get queue health status.
     * Fixed: Correct table name (offline_queue) and column names (state, queued_at).
     * Optimized: Single query with conditional aggregate.
     */
    private function getQueueHealth(string $organizationId): array
    {
        $thirtyMinutesAgo = now()->subMinutes(30);

        $stats = DB::table('offline_queue')
            ->where('organization_id', $organizationId)
            ->where('state', 'pending')
            ->selectRaw("
                COUNT(*) as pending,
                SUM(CASE WHEN queued_at < ? THEN 1 ELSE 0 END) as stuck
            ", [$thirtyMinutesAgo])
            ->first();

        $pending = (int) ($stats->pending ?? 0);
        $stuck = (int) ($stats->stuck ?? 0);

        return [
            'pending' => $pending,
            'stuck' => $stuck,
            'healthy' => $stuck === 0,
        ];
    }

    /**
     * Get certificate health status.
     */
    private function getCertificateHealth(string $organizationId): array
    {
        $activeCert = $this->certificateService->getActiveCertificate($organizationId);

        if (!$activeCert) {
            return [
                'status' => 'missing',
                'message' => 'No active certificate found',
            ];
        }

        $expiryDate = new \DateTimeImmutable($activeCert['valid_to']);
        $daysUntilExpiry = (int) $expiryDate->diff(now())->format('%r%a');

        if ($daysUntilExpiry < 0) {
            return [
                'status' => 'expired',
                'message' => 'Certificate has expired',
                'expired_days_ago' => abs($daysUntilExpiry),
            ];
        }

        if ($daysUntilExpiry <= 7) {
            return [
                'status' => 'critical',
                'message' => "Certificate expires in {$daysUntilExpiry} days",
                'days_remaining' => $daysUntilExpiry,
            ];
        }

        if ($daysUntilExpiry <= 30) {
            return [
                'status' => 'warning',
                'message' => "Certificate expires in {$daysUntilExpiry} days",
                'days_remaining' => $daysUntilExpiry,
            ];
        }

        return [
            'status' => 'healthy',
            'days_remaining' => $daysUntilExpiry,
        ];
    }

    /**
     * Check if hash chain is intact.
     */
    private function isHashChainIntact(string $organizationId): bool
    {
        // Simple check - verify last entry matches state
        $state = DB::table('hash_chain_state')
            ->where('organization_id', $organizationId)
            ->first();

        if (!$state) {
            return true; // No chain yet
        }

        $lastEntry = DB::table('hash_chain_history')
            ->where('organization_id', $organizationId)
            ->orderByDesc('icv')
            ->first();

        if (!$lastEntry) {
            return false; // State exists but no history
        }

        return $lastEntry->icv === $state->last_icv;
    }

    /**
     * Get usage over time for charts.
     */
    private function getUsageOverTime(string $organizationId, int $days): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        $invoices = DB::table('invoices')
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        $submissions = DB::table('invoice_submissions')
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Fill in missing dates with zeros
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dates[] = [
                'date' => $date,
                'invoices' => $invoices[$date] ?? 0,
                'submissions' => $submissions[$date] ?? 0,
            ];
        }

        return [
            'period' => "{$days}d",
            'data' => $dates,
        ];
    }

    /**
     * Determine overall health status.
     */
    private function determineOverallHealth(array $data): string
    {
        // Check circuit breaker
        if (($data['circuit_breaker']['state'] ?? 'closed') === 'open') {
            return 'critical';
        }

        // Check certificate
        $certStatus = $data['certificates']['status'] ?? 'unknown';
        if (in_array($certStatus, ['expired', 'missing'])) {
            return 'critical';
        }
        if ($certStatus === 'critical') {
            return 'critical';
        }

        // Check queue
        if (($data['queue']['stuck'] ?? 0) > 10) {
            return 'warning';
        }

        // Check certificate warning
        if ($certStatus === 'warning') {
            return 'warning';
        }

        return 'healthy';
    }
}
