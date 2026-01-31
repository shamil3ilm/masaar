<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Compliance\Zatca\Services\ClusterCircuitBreaker;
use App\Domains\Compliance\Zatca\Services\EnvironmentVarianceTracker;
use App\Domains\Compliance\Zatca\Services\CertificateLineageService;
use App\Domains\Compliance\Zatca\Services\QueueHealthMonitor;
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
        private readonly QueueHealthMonitor $queueMonitor,
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
                'clearance_state',
                'reporting_status',
                'created_at',
                'updated_at',
            ])
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'invoice_id' => $s->invoice_id,
                'status' => $s->clearance_state ?? $s->reporting_status ?? 'pending',
                'timestamp' => $s->updated_at ?? $s->created_at,
            ]);

        return ApiResponse::success([
            'activities' => $activities,
            'count' => $activities->count(),
        ]);
    }

    /**
     * Get invoice statistics.
     */
    private function getInvoiceStats(string $organizationId): array
    {
        $today = now()->startOfDay();
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        return [
            'total' => DB::table('invoices')
                ->where('organization_id', $organizationId)
                ->count(),
            'today' => DB::table('invoices')
                ->where('organization_id', $organizationId)
                ->where('created_at', '>=', $today)
                ->count(),
            'this_month' => DB::table('invoices')
                ->where('organization_id', $organizationId)
                ->where('created_at', '>=', $thisMonth)
                ->count(),
            'last_month' => DB::table('invoices')
                ->where('organization_id', $organizationId)
                ->where('created_at', '>=', $lastMonth)
                ->where('created_at', '<', $thisMonth)
                ->count(),
            'by_type' => DB::table('invoices')
                ->where('organization_id', $organizationId)
                ->selectRaw('invoice_type_code, COUNT(*) as count')
                ->groupBy('invoice_type_code')
                ->pluck('count', 'invoice_type_code')
                ->toArray(),
            'total_amount' => DB::table('invoices')
                ->where('organization_id', $organizationId)
                ->sum('total') ?? 0,
        ];
    }

    /**
     * Get submission statistics.
     */
    private function getSubmissionStats(string $organizationId): array
    {
        return [
            'total' => DB::table('invoice_submissions')
                ->where('organization_id', $organizationId)
                ->count(),
            'cleared' => DB::table('invoice_submissions')
                ->where('organization_id', $organizationId)
                ->where('clearance_state', 'cleared')
                ->count(),
            'reported' => DB::table('invoice_submissions')
                ->where('organization_id', $organizationId)
                ->where('clearance_state', 'reported')
                ->count(),
            'rejected' => DB::table('invoice_submissions')
                ->where('organization_id', $organizationId)
                ->where('clearance_state', 'rejected')
                ->count(),
            'pending' => DB::table('invoice_submissions')
                ->where('organization_id', $organizationId)
                ->whereIn('clearance_state', ['pending_clearance', 'submitted', 'unknown'])
                ->count(),
            'success_rate' => $this->calculateSuccessRate($organizationId),
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
     */
    private function getQueueHealth(string $organizationId): array
    {
        $queueSize = DB::table('offline_invoice_queue')
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->count();

        $stuckItems = DB::table('offline_invoice_queue')
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(30))
            ->count();

        return [
            'pending' => $queueSize,
            'stuck' => $stuckItems,
            'healthy' => $stuckItems === 0,
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
     * Calculate submission success rate.
     */
    private function calculateSuccessRate(string $organizationId): float
    {
        $total = DB::table('invoice_submissions')
            ->where('organization_id', $organizationId)
            ->whereIn('clearance_state', ['cleared', 'reported', 'rejected'])
            ->count();

        if ($total === 0) {
            return 100.0;
        }

        $successful = DB::table('invoice_submissions')
            ->where('organization_id', $organizationId)
            ->whereIn('clearance_state', ['cleared', 'reported'])
            ->count();

        return round(($successful / $total) * 100, 2);
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
