<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Compliance\Fatoora\Services\ClusterCircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\EnvironmentVarianceTracker;
use App\Domains\Compliance\Fatoora\Services\OfflineQueueManager;
use App\Domains\Compliance\Fatoora\Services\FatooraConnectivityChecker;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;

/**
 * Admin Dashboard Controller.
 *
 * Platform-wide statistics and system health monitoring.
 * Requires admin authentication (not tenant-scoped).
 *
 * Note: This controller should be protected by admin middleware.
 */
class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly ClusterCircuitBreaker $circuitBreaker,
        private readonly EnvironmentVarianceTracker $varianceTracker,
        private readonly OfflineQueueManager $offlineQueueManager,
        private readonly FatooraConnectivityChecker $connectivityChecker,
    ) {}

    /**
     * Get platform-wide overview.
     *
     * GET /api/admin/dashboard
     */
    public function index(): JsonResponse
    {
        $data = Cache::remember('admin:dashboard:overview', 60, function () {
            return [
                'organizations' => $this->getOrganizationStats(),
                'invoices' => $this->getPlatformInvoiceStats(),
                'submissions' => $this->getPlatformSubmissionStats(),
                'system' => $this->getSystemHealth(),
                'generated_at' => now()->toIso8601String(),
            ];
        });

        return ApiResponse::success($data);
    }

    /**
     * Get system health status.
     *
     * GET /api/admin/dashboard/health
     */
    public function health(): JsonResponse
    {
        $data = [
            'circuit_breaker' => $this->circuitBreaker->getMetrics('zatca_api'),
            'database' => $this->getDatabaseHealth(),
            'cache' => $this->getCacheHealth(),
            'queue' => $this->getQueueHealth(),
            'checked_at' => now()->toIso8601String(),
        ];

        $data['overall_status'] = $this->determineSystemHealth($data);

        return ApiResponse::success($data);
    }

    /**
     * Get top organizations by usage.
     *
     * GET /api/admin/dashboard/top-organizations?limit=10
     */
    public function topOrganizations(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 10), 50);

        $organizations = Cache::remember("admin:top_orgs:{$limit}", 300, function () use ($limit) {
            return DB::table('invoices')
                ->join('organizations', 'invoices.organization_id', '=', 'organizations.id')
                ->selectRaw('organizations.id, organizations.name, COUNT(invoices.id) as invoice_count, SUM(invoices.total) as total_amount')
                ->groupBy('organizations.id', 'organizations.name')
                ->orderByDesc('invoice_count')
                ->limit($limit)
                ->get();
        });

        return ApiResponse::success([
            'organizations' => $organizations,
            'count' => $organizations->count(),
        ]);
    }

    /**
     * Run index health check manually.
     *
     * POST /api/admin/dashboard/run-health-check
     */
    public function runHealthCheck(): JsonResponse
    {
        try {
            Artisan::call('compliance:index-health', ['--json' => true]);
            $output = Artisan::output();

            return ApiResponse::success([
                'result' => json_decode($output, true) ?? $output,
                'ran_at' => now()->toIso8601String(),
            ], 'Health check completed');
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to run health check: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get environment variance comparison (sandbox vs production).
     *
     * GET /api/admin/dashboard/variances?limit=50
     *
     * Tracks cases where behavior differs between sandbox and production
     * environments. Critical for auditing and regulatory compliance.
     */
    public function environmentVariances(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 50), 200);

        $variances = DB::table('environment_variance_log')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn($row) => [
                'id' => $row->id,
                'rule_code' => $row->rule_code,
                'sandbox_result' => $row->sandbox_result,
                'production_result' => $row->production_result,
                'variance_type' => $row->variance_type,
                'organization_id' => $row->organization_id,
                'invoice_id' => $row->invoice_id ?? null,
                'detected_at' => $row->created_at,
                'resolved' => (bool) ($row->resolved_at ?? false),
                'notes' => $row->notes ?? null,
            ]);

        // Aggregate statistics
        $stats = [
            'total_variances' => DB::table('environment_variance_log')->count(),
            'unresolved' => DB::table('environment_variance_log')
                ->whereNull('resolved_at')
                ->count(),
            'by_rule_code' => DB::table('environment_variance_log')
                ->selectRaw('rule_code, COUNT(*) as count')
                ->groupBy('rule_code')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'rule_code')
                ->toArray(),
            'last_7_days' => DB::table('environment_variance_log')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
        ];

        return ApiResponse::success([
            'variances' => $variances,
            'statistics' => $stats,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get hash chain health metrics for longevity monitoring.
     *
     * GET /api/admin/dashboard/hash-chain-health
     *
     * Monitors P95/P99 latency for hash chain queries to detect
     * degradation before it becomes critical.
     */
    public function hashChainHealth(): JsonResponse
    {
        $metrics = Cache::remember('admin:hash_chain_health', 60, function () {
            // Sample hash chain query performance
            $samples = [];
            for ($i = 0; $i < 5; $i++) {
                $start = microtime(true);
                DB::table('hash_chain_history')
                    ->orderByDesc('icv')
                    ->limit(1)
                    ->first();
                $samples[] = (microtime(true) - $start) * 1000;
            }

            sort($samples);
            $p95Index = (int) floor(count($samples) * 0.95);
            $p99Index = (int) floor(count($samples) * 0.99);

            $thresholds = config('fatoora.hash_chain_monitoring');

            return [
                'samples' => count($samples),
                'avg_ms' => round(array_sum($samples) / count($samples), 2),
                'p95_ms' => round($samples[$p95Index] ?? end($samples), 2),
                'p99_ms' => round($samples[$p99Index] ?? end($samples), 2),
                'thresholds' => [
                    'p95_warning' => $thresholds['p95_warning_ms'] ?? 50,
                    'p99_critical' => $thresholds['p99_critical_ms'] ?? 200,
                ],
                'row_count' => DB::table('hash_chain_history')->count(),
                'oldest_entry' => DB::table('hash_chain_history')
                    ->orderBy('created_at')
                    ->value('created_at'),
            ];
        });

        $status = 'healthy';
        if ($metrics['p99_ms'] > ($metrics['thresholds']['p99_critical'] ?? 200)) {
            $status = 'critical';
        } elseif ($metrics['p95_ms'] > ($metrics['thresholds']['p95_warning'] ?? 50)) {
            $status = 'warning';
        }

        return ApiResponse::success([
            'metrics' => $metrics,
            'status' => $status,
            'recommendation' => $status !== 'healthy'
                ? 'Consider partitioning hash_chain_history table or adding indexes'
                : null,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get error rates over time.
     *
     * GET /api/admin/dashboard/error-rates?period=24h
     */
    public function errorRates(Request $request): JsonResponse
    {
        $period = $request->query('period', '24h');
        $hours = match ($period) {
            '1h' => 1,
            '6h' => 6,
            '24h' => 24,
            '7d' => 168,
            default => 24,
        };

        $startTime = now()->subHours($hours);

        $data = DB::table('invoice_submissions')
            ->where('created_at', '>=', $startTime)
            ->selectRaw("
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour,
                COUNT(*) as total,
                SUM(CASE WHEN state = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN state IN ('cleared', 'reported') THEN 1 ELSE 0 END) as successful
            ")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn($row) => [
                'hour' => $row->hour,
                'total' => $row->total,
                'rejected' => $row->rejected,
                'successful' => $row->successful,
                'error_rate' => $row->total > 0 ? round(($row->rejected / $row->total) * 100, 2) : 0,
            ]);

        return ApiResponse::success([
            'period' => $period,
            'data' => $data,
        ]);
    }

    /**
     * Get organization statistics.
     */
    private function getOrganizationStats(): array
    {
        return [
            'total' => DB::table('organizations')->count(),
            'active' => DB::table('organizations')
                ->where('status', 'active')
                ->count(),
            'with_certificate' => DB::table('certificate_lineage')
                ->distinct('organization_id')
                ->count('organization_id'),
        ];
    }

    /**
     * Get platform-wide invoice statistics.
     * Optimized: Single query with conditional aggregates instead of 3 separate queries.
     */
    private function getPlatformInvoiceStats(): array
    {
        $today = now()->startOfDay();
        $thisHour = now()->startOfHour();

        $stats = DB::table('invoices')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as this_hour
            ", [$today, $thisHour])
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'today' => (int) ($stats->today ?? 0),
            'this_hour' => (int) ($stats->this_hour ?? 0),
        ];
    }

    /**
     * Get platform-wide submission statistics.
     * Optimized: Single query with conditional aggregates instead of 5 separate queries.
     */
    private function getPlatformSubmissionStats(): array
    {
        $stats = DB::table('invoice_submissions')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN state = 'cleared' THEN 1 ELSE 0 END) as cleared,
                SUM(CASE WHEN state = 'reported' THEN 1 ELSE 0 END) as reported,
                SUM(CASE WHEN state = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN state IN ('pending_submission', 'submitted', 'queued') THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'cleared' => (int) ($stats->cleared ?? 0),
            'reported' => (int) ($stats->reported ?? 0),
            'rejected' => (int) ($stats->rejected ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
        ];
    }

    /**
     * Get system health summary.
     */
    private function getSystemHealth(): array
    {
        $circuitState = $this->circuitBreaker->getState('zatca_api');

        return [
            'circuit_breaker' => $circuitState,
            'queue_size' => DB::table('offline_queue')
                ->where('state', 'pending')
                ->count(),
        ];
    }

    /**
     * Get database health metrics.
     */
    private function getDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get cache health metrics.
     */
    private function getCacheHealth(): array
    {
        try {
            $testKey = 'health_check_' . uniqid();
            Cache::put($testKey, true, 10);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);

            return [
                'status' => $retrieved === true ? 'healthy' : 'degraded',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get queue health metrics.
     * Optimized: Single query with conditional aggregates instead of 2 separate queries.
     */
    private function getQueueHealth(): array
    {
        $thirtyMinutesAgo = now()->subMinutes(30);

        $stats = DB::table('offline_queue')
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
            'status' => $stuck === 0 ? 'healthy' : ($stuck > 50 ? 'critical' : 'warning'),
        ];
    }

    /**
     * Determine overall system health.
     */
    private function determineSystemHealth(array $data): string
    {
        if (($data['database']['status'] ?? '') === 'unhealthy') {
            return 'critical';
        }

        if (($data['circuit_breaker']['state'] ?? 'closed') === 'open') {
            return 'critical';
        }

        if (($data['queue']['status'] ?? '') === 'critical') {
            return 'critical';
        }

        if (($data['cache']['status'] ?? '') === 'unhealthy') {
            return 'warning';
        }

        if (($data['queue']['status'] ?? '') === 'warning') {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Get ZATCA API connectivity status.
     *
     * GET /api/admin/dashboard/connectivity
     */
    public function connectivity(): JsonResponse
    {
        $status = $this->connectivityChecker->getDetailedStatus();

        return ApiResponse::success([
            'zatca_api' => $status,
            'mode' => $this->connectivityChecker->shouldUseOfflineMode() ? 'offline' : 'online',
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Force refresh connectivity check.
     *
     * POST /api/admin/dashboard/connectivity/refresh
     */
    public function refreshConnectivity(): JsonResponse
    {
        $status = $this->connectivityChecker->forceCheck();

        return ApiResponse::success([
            'zatca_api' => $status,
            'mode' => $status['available'] ? 'online' : 'offline',
            'refreshed_at' => now()->toIso8601String(),
        ], 'Connectivity status refreshed');
    }

    /**
     * Get offline queue status across all organizations.
     *
     * GET /api/admin/dashboard/offline-queue
     */
    public function offlineQueue(): JsonResponse
    {
        $data = Cache::remember('admin:offline_queue', 30, function () {
            $stats = DB::table('offline_queue')
                ->selectRaw("
                    state,
                    COUNT(*) as count,
                    COUNT(DISTINCT organization_id) as organizations
                ")
                ->groupBy('state')
                ->get()
                ->keyBy('state');

            $oldestPending = DB::table('offline_queue')
                ->where('state', 'pending')
                ->orderBy('queued_at')
                ->value('queued_at');

            $recentFailures = DB::table('offline_queue')
                ->where('state', 'failed')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get(['id', 'invoice_id', 'organization_id', 'last_error', 'attempts', 'updated_at']);

            return [
                'summary' => [
                    'pending' => $stats->get('pending')?->count ?? 0,
                    'processing' => $stats->get('processing')?->count ?? 0,
                    'completed' => $stats->get('completed')?->count ?? 0,
                    'failed' => $stats->get('failed')?->count ?? 0,
                ],
                'organizations_affected' => [
                    'pending' => $stats->get('pending')?->organizations ?? 0,
                    'failed' => $stats->get('failed')?->organizations ?? 0,
                ],
                'oldest_pending_at' => $oldestPending,
                'recent_failures' => $recentFailures,
            ];
        });

        return ApiResponse::success($data);
    }

    /**
     * Get offline queue for specific organization.
     *
     * GET /api/admin/dashboard/offline-queue/{organizationId}
     */
    public function offlineQueueByOrg(string $organizationId): JsonResponse
    {
        $status = $this->offlineQueueManager->getStatus($organizationId);

        $items = DB::table('offline_queue')
            ->where('organization_id', $organizationId)
            ->whereIn('state', ['pending', 'processing', 'failed'])
            ->orderByDesc('queued_at')
            ->limit(50)
            ->get();

        return ApiResponse::success([
            'status' => $status,
            'items' => $items,
        ]);
    }

    /**
     * Trigger offline queue processing.
     *
     * POST /api/admin/dashboard/offline-queue/process
     */
    public function processOfflineQueue(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id');
        $limit = min((int) $request->input('limit', 50), 200);

        try {
            $params = ['--limit' => $limit];
            if ($organizationId) {
                $params['--organization'] = $organizationId;
            }

            Artisan::call('zatca:process-offline', $params);
            $output = Artisan::output();

            Cache::forget('admin:offline_queue');

            return ApiResponse::success([
                'output' => $output,
                'processed_at' => now()->toIso8601String(),
            ], 'Offline queue processing triggered');
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to process offline queue: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Retry a specific failed queue item.
     *
     * POST /api/admin/dashboard/offline-queue/{queueId}/retry
     */
    public function retryQueueItem(string $queueId): JsonResponse
    {
        $item = $this->offlineQueueManager->getItem($queueId);

        if (!$item) {
            return ApiResponse::error('Queue item not found', 404);
        }

        if ($item->state !== 'failed') {
            return ApiResponse::error('Only failed items can be retried', 400);
        }

        // Reset to pending
        DB::table('offline_queue')
            ->where('id', $queueId)
            ->update([
                'state' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

        return ApiResponse::success([
            'queue_id' => $queueId,
            'new_state' => 'pending',
        ], 'Queue item reset for retry');
    }

    /**
     * Get recent issues and alerts.
     *
     * GET /api/admin/dashboard/issues
     */
    public function issues(): JsonResponse
    {
        $data = Cache::remember('admin:issues', 60, function () {
            $issues = [];

            // Check connectivity
            $connectivity = $this->connectivityChecker->check();
            if (!$connectivity['available']) {
                $issues[] = [
                    'type' => 'connectivity',
                    'severity' => 'critical',
                    'message' => 'ZATCA API is unavailable',
                    'details' => $connectivity['reason'],
                ];
            }

            // Check circuit breaker
            $cbState = $this->circuitBreaker->getState('zatca_api');
            if ($cbState === 'open') {
                $issues[] = [
                    'type' => 'circuit_breaker',
                    'severity' => 'critical',
                    'message' => 'Circuit breaker is open due to repeated failures',
                ];
            }

            // Check offline queue backlog
            $pendingCount = DB::table('offline_queue')
                ->where('state', 'pending')
                ->count();
            if ($pendingCount > 100) {
                $issues[] = [
                    'type' => 'offline_queue',
                    'severity' => $pendingCount > 500 ? 'critical' : 'warning',
                    'message' => "Offline queue has {$pendingCount} pending items",
                ];
            }

            // Check failed items
            $failedCount = DB::table('offline_queue')
                ->where('state', 'failed')
                ->count();
            if ($failedCount > 0) {
                $issues[] = [
                    'type' => 'failed_submissions',
                    'severity' => $failedCount > 50 ? 'critical' : 'warning',
                    'message' => "{$failedCount} failed items in offline queue",
                ];
            }

            // Check expiring certificates
            $expiringCerts = DB::table('certificate_lineage')
                ->where('status', 'active')
                ->whereRaw("expires_at <= ?", [now()->addDays(7)])
                ->count();
            if ($expiringCerts > 0) {
                $issues[] = [
                    'type' => 'certificate_expiry',
                    'severity' => 'critical',
                    'message' => "{$expiringCerts} certificate(s) expiring within 7 days",
                ];
            }

            // Check recent rejections
            $recentRejections = DB::table('invoice_submissions')
                ->where('state', 'rejected')
                ->where('created_at', '>=', now()->subHours(24))
                ->count();
            if ($recentRejections > 10) {
                $issues[] = [
                    'type' => 'rejections',
                    'severity' => 'warning',
                    'message' => "{$recentRejections} rejections in the last 24 hours",
                ];
            }

            return $issues;
        });

        return ApiResponse::success([
            'issues' => $data,
            'count' => count($data),
            'has_critical' => collect($data)->contains('severity', 'critical'),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get submission logs for troubleshooting.
     *
     * GET /api/admin/dashboard/logs?limit=50&state=failed
     */
    public function logs(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 50), 200);
        $state = $request->query('state');
        $organizationId = $request->query('organization_id');

        $query = DB::table('invoice_submissions')
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($state) {
            $query->where('state', $state);
        }

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $logs = $query->get([
            'id',
            'invoice_id',
            'organization_id',
            'state',
            'submission_type',
            'clearance_status',
            'reporting_status',
            'last_error_code',
            'last_error_message',
            'retry_count',
            'created_at',
            'completed_at',
        ]);

        return ApiResponse::success([
            'logs' => $logs,
            'count' => $logs->count(),
            'filters' => [
                'state' => $state,
                'organization_id' => $organizationId,
            ],
        ]);
    }

    /**
     * Reset circuit breaker manually.
     *
     * POST /api/admin/dashboard/circuit-breaker/reset
     */
    public function resetCircuitBreaker(): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        $result = $this->circuitBreaker->forceState(
            'zatca_api',
            ClusterCircuitBreaker::STATE_CLOSED,
            'Manual reset via admin dashboard',
            $user?->email ?? 'system'
        );

        $this->connectivityChecker->resetCircuitBreaker();

        return ApiResponse::success([
            'circuit_breaker' => $result,
            'reset_at' => now()->toIso8601String(),
        ], 'Circuit breaker reset successfully');
    }
}
