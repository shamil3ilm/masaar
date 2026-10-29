<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Contracts\FilingOperations;
use App\Domains\Compliance\Engine\Enums\RequeueOutcome;
use App\Domains\Platform\DTOs\FilterData;
use App\Domains\Platform\Services\ChainHealth;
use App\Domains\Platform\Services\IssueDetector;
use App\Domains\Platform\Services\OrganizationReport;
use App\Domains\Platform\Services\PlatformStatus;
use App\Domains\Platform\Services\QueueReport;
use App\Domains\Platform\Services\SubmissionLog;
use App\Http\Controllers\Controller;
use App\Http\Pagination\PageSize;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

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
        private readonly PlatformStatus $status,
        private readonly FilingOperations $filing,
        private readonly OrganizationReport $organizations,
        private readonly SubmissionLog $submissionLog,
        private readonly QueueReport $queue,
        private readonly ChainHealth $chainHealth,
        private readonly IssueDetector $issueDetector,
    ) {}

    /**
     * Get platform-wide overview.
     *
     * GET /api/admin/dashboard
     */
    public function index(): JsonResponse
    {
        $data = Cache::remember(
            'admin:dashboard:overview',
            60,
            fn () => $this->status->overview()
        );

        return ApiResponse::success($data);
    }

    /**
     * Get system health status.
     *
     * GET /api/admin/dashboard/health
     */
    public function health(): JsonResponse
    {
        return ApiResponse::success($this->status->health());
    }

    /**
     * Get top organizations by usage.
     *
     * GET /api/admin/dashboard/top-organizations?limit=10
     */
    public function topOrganizations(Request $request): JsonResponse
    {
        $limit = PageSize::fromRequest($request, 10, 50, 'limit');

        $organizations = Cache::remember(
            "admin:top_orgs:{$limit}",
            300,
            fn () => $this->organizations->topByInvoices($limit)
        );

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
        Artisan::call('compliance:index-health', ['--json' => true]);
        $output = Artisan::output();

        return ApiResponse::success([
            'result' => json_decode($output, true) ?? $output,
            'ran_at' => now()->toIso8601String(),
        ], 'Health check completed');
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
        $metrics = Cache::remember('admin:hash_chain_health', 60, fn () => $this->chainHealth->measure());
        $status = $this->chainHealth->status($metrics);

        return ApiResponse::success([
            'metrics' => $metrics,
            'status' => $status,
            'recommendation' => $this->chainHealth->recommendation($status),
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

        return ApiResponse::success([
            'period' => $period,
            'data' => $this->submissionLog->errorRates($hours),
        ]);
    }

    /**
     * Get ZATCA API connectivity status.
     *
     * GET /api/admin/dashboard/connectivity
     */
    public function connectivity(): JsonResponse
    {
        $status = $this->filing->detailedStatus();

        return ApiResponse::success([
            'zatca_api' => $status,
            'mode' => $this->filing->offline() ? 'offline' : 'online',
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
        $status = $this->filing->recheck();

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
        $data = Cache::remember('admin:offline_queue', 30, fn () => $this->queue->summary());

        return ApiResponse::success($data);
    }

    /**
     * Get offline queue for specific organization.
     *
     * GET /api/admin/dashboard/offline-queue/{organizationId}
     */
    public function offlineQueueByOrg(string $organizationId): JsonResponse
    {
        return ApiResponse::success([
            'status' => $this->queue->statusOf($organizationId),
            'items' => $this->queue->openItems($organizationId, 50),
        ]);
    }

    /**
     * Trigger offline queue processing.
     *
     * POST /api/admin/dashboard/offline-queue/process
     */
    public function processOfflineQueue(Request $request): JsonResponse
    {
        // A body, not a query string, so validated rather than read through
        // PageSize. A negative limit reached the command, and a failure to
        // queue answered with its own message attached; the global renderer
        // logs that and answers a generic 500.
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1'],
            'org_id' => ['nullable', 'uuid'],
        ]);

        $organizationId = $validated['org_id'] ?? null;
        $limit = min((int) ($validated['limit'] ?? 50), 200);

        $params = ['--limit' => $limit];
        if ($organizationId) {
            $params['--organization'] = $organizationId;
        }

        // Queued, not called.
        //
        // Artisan::call ran the whole drain inside this request. The
        // command walks every organization with pending items and submits
        // up to --limit invoices one at a time, and FatooraClient gives
        // each ZATCA call a 30 second timeout with retry(3, 1000) - and
        // Guzzle maps a read timeout to a ConnectException, so a read
        // timeout is retried too. That is about 92 seconds per invoice at
        // worst, against a limit this endpoint allows up to 200. The
        // request could not have returned, and the operator pressing the
        // button would have had a gateway timeout and no idea whether any
        // of it had happened.
        //
        // There is nothing to wait for: the same command runs on a
        // schedule every five minutes, and this endpoint only means "go
        // now". The two default-queue workers pick it up.
        Artisan::queue('fatoora:process-offline', $params);

        Cache::forget('admin:offline_queue');

        return ApiResponse::success([
            'limit' => $limit,
            'org_id' => $organizationId,
            'queued_at' => now()->toIso8601String(),
        ], 'Offline queue processing queued', 202);
    }

    /**
     * Retry a specific failed queue item.
     *
     * POST /api/admin/dashboard/offline-queue/{queueId}/retry
     */
    public function retryQueueItem(string $queueId): JsonResponse
    {
        return match ($this->filing->requeue($queueId)) {
            RequeueOutcome::NotFound => ApiResponse::error('Queue item not found', 404),
            RequeueOutcome::NotFailed => ApiResponse::error('Only failed items can be retried', 400),
            RequeueOutcome::Requeued => ApiResponse::success([
                'queue_id' => $queueId,
                'new_state' => 'pending',
            ], 'Queue item reset for retry'),
        };
    }

    /**
     * Get recent issues and alerts.
     *
     * GET /api/admin/dashboard/issues
     */
    public function issues(): JsonResponse
    {
        $data = Cache::remember('admin:issues', 60, fn () => $this->issueDetector->detect());

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
        // min() alone let limit=-1 through, which the query builder ignores:
        // the whole table in one response.
        $limit = PageSize::fromRequest($request, 50, 200, 'limit');
        $state = $request->query('state');
        $organizationId = $request->query('org_id');

        $logs = $this->submissionLog->latest(FilterData::fromQuery($state, $organizationId), $limit);

        return ApiResponse::success([
            'logs' => $logs,
            'count' => $logs->count(),
            'filters' => [
                'state' => $state,
                'org_id' => $organizationId,
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
        /** @var User|null $user */
        $user = auth()->user();

        $result = $this->filing->resetCircuit($user?->email ?? 'system');

        return ApiResponse::success([
            'circuit_breaker' => $result,
            'reset_at' => now()->toIso8601String(),
        ], 'Circuit breaker reset');
    }
}
