<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Queue Health Monitor.
 *
 * Detects silent failures in the offline queue system:
 * - Items stuck in queue without retry attempts
 * - Repeated failures without alerts
 * - Queue growth without processing
 * - Processing rate anomalies
 *
 * Integrates with alerting systems to prevent silent data loss.
 */
class QueueHealthMonitor
{
    /**
     * Cache keys.
     */
    private const LAST_CHECK_KEY = 'queue_health:last_check';
    private const ALERT_COOLDOWN_KEY = 'queue_health:alert_cooldown:';
    private const METRICS_KEY = 'queue_health:metrics';

    /**
     * Get stuck item threshold in minutes from config.
     */
    private function getStuckItemThresholdMinutes(): int
    {
        return (int) config('fatoora.queue_health.stuck_item_threshold_minutes', 30);
    }

    /**
     * Get max retry count from config.
     */
    private function getMaxRetryCount(): int
    {
        return (int) config('fatoora.queue_health.max_retry_count', 5);
    }

    /**
     * Get queue growth threshold from config.
     */
    private function getQueueGrowthThreshold(): int
    {
        return (int) config('fatoora.queue_health.queue_growth_threshold', 100);
    }

    /**
     * Get minimum processing rate per hour from config.
     */
    private function getProcessingRateMinPerHour(): int
    {
        return (int) config('fatoora.queue_health.processing_rate_min_per_hour', 10);
    }

    /**
     * Get alert cooldown minutes from config.
     */
    private function getAlertCooldownMinutes(): int
    {
        return (int) config('fatoora.queue_health.alert_cooldown_minutes', 30);
    }

    /**
     * Run health check and return status.
     */
    public function runHealthCheck(): array
    {
        $results = [
            'checked_at' => now()->toIso8601String(),
            'status' => 'healthy',
            'checks' => [],
            'alerts' => [],
        ];

        // Run all checks
        $results['checks']['stuck_items'] = $this->checkStuckItems();
        $results['checks']['retry_exhaustion'] = $this->checkRetryExhaustion();
        $results['checks']['queue_growth'] = $this->checkQueueGrowth();
        $results['checks']['processing_rate'] = $this->checkProcessingRate();
        $results['checks']['silent_failures'] = $this->checkSilentFailures();

        // Aggregate alerts
        foreach ($results['checks'] as $checkName => $check) {
            if ($check['status'] === 'alert') {
                $results['status'] = 'unhealthy';
                $results['alerts'][] = [
                    'check' => $checkName,
                    'severity' => $check['severity'] ?? 'warning',
                    'message' => $check['message'],
                    'details' => $check['details'] ?? [],
                ];
            }
        }

        // Store metrics for trending
        $this->storeMetrics($results);

        // Send alerts if needed
        if (!empty($results['alerts'])) {
            $this->processAlerts($results['alerts']);
        }

        Cache::put(self::LAST_CHECK_KEY, now()->toIso8601String(), 3600);

        return $results;
    }

    /**
     * Check for items stuck in queue without processing.
     */
    private function checkStuckItems(): array
    {
        $threshold = now()->subMinutes($this->getStuckItemThresholdMinutes());

        $stuckItems = DB::table('offline_queue')
            ->where('state', 'pending')
            ->where('queued_at', '<', $threshold)
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<', now());
            })
            ->select(['id', 'invoice_id', 'organization_id', 'queued_at', 'attempts'])
            ->limit(100)
            ->get();

        if ($stuckItems->isEmpty()) {
            return [
                'status' => 'healthy',
                'message' => 'No stuck items detected',
                'count' => 0,
            ];
        }

        return [
            'status' => 'alert',
            'severity' => 'warning',
            'message' => sprintf('%d items stuck in queue for >%d minutes', $stuckItems->count(), $this->getStuckItemThresholdMinutes()),
            'count' => $stuckItems->count(),
            'details' => [
                'oldest_item' => $stuckItems->first()?->queued_at,
                'sample_ids' => $stuckItems->pluck('invoice_id')->take(10)->toArray(),
                'by_organization' => $stuckItems->groupBy('organization_id')->map->count()->toArray(),
            ],
        ];
    }

    /**
     * Check for items that have exhausted retries without success.
     */
    private function checkRetryExhaustion(): array
    {
        $exhaustedItems = DB::table('offline_queue')
            ->where('attempts', '>=', $this->getMaxRetryCount())
            ->where('state', '!=', 'submitted')
            ->select(['id', 'invoice_id', 'organization_id', 'attempts', 'last_error', 'queued_at'])
            ->get();

        if ($exhaustedItems->isEmpty()) {
            return [
                'status' => 'healthy',
                'message' => 'No retry exhaustion detected',
                'count' => 0,
            ];
        }

        // Group errors for analysis
        $errorGroups = $exhaustedItems->groupBy(function ($item) {
            // Extract error type from last_error
            $error = $item->last_error ?? 'unknown';
            if (str_contains($error, 'timeout')) return 'timeout';
            if (str_contains($error, 'certificate')) return 'certificate';
            if (str_contains($error, 'validation')) return 'validation';
            if (str_contains($error, 'network')) return 'network';
            return 'other';
        })->map->count();

        return [
            'status' => 'alert',
            'severity' => 'critical',
            'message' => sprintf('%d items have exhausted all %d retry attempts', $exhaustedItems->count(), $this->getMaxRetryCount()),
            'count' => $exhaustedItems->count(),
            'details' => [
                'error_distribution' => $errorGroups->toArray(),
                'oldest_exhausted' => $exhaustedItems->min('queued_at'),
                'sample_errors' => $exhaustedItems->pluck('last_error')->unique()->take(5)->toArray(),
            ],
        ];
    }

    /**
     * Check for abnormal queue growth.
     */
    private function checkQueueGrowth(): array
    {
        // Get current queue size
        $currentSize = DB::table('offline_queue')
            ->where('state', 'pending')
            ->count();

        // Get size from 1 hour ago (stored in metrics)
        $previousMetrics = Cache::get(self::METRICS_KEY . ':hourly');
        $previousSize = $previousMetrics['queue_size'] ?? 0;

        $growth = $currentSize - $previousSize;

        if ($growth < $this->getQueueGrowthThreshold()) {
            return [
                'status' => 'healthy',
                'message' => 'Queue growth within normal limits',
                'current_size' => $currentSize,
                'growth' => $growth,
            ];
        }

        return [
            'status' => 'alert',
            'severity' => $growth > $this->getQueueGrowthThreshold() * 2 ? 'critical' : 'warning',
            'message' => sprintf('Queue grew by %d items in last hour (threshold: %d)', $growth, $this->getQueueGrowthThreshold()),
            'current_size' => $currentSize,
            'growth' => $growth,
            'details' => [
                'previous_size' => $previousSize,
                'growth_rate' => $previousSize > 0 ? round(($growth / $previousSize) * 100, 2) . '%' : 'N/A',
            ],
        ];
    }

    /**
     * Check processing rate for anomalies.
     */
    private function checkProcessingRate(): array
    {
        // Count items processed in last hour
        $processedLastHour = DB::table('offline_queue')
            ->where('state', 'submitted')
            ->where('updated_at', '>=', now()->subHour())
            ->count();

        // Get pending items
        $pendingCount = DB::table('offline_queue')
            ->where('state', 'pending')
            ->count();

        // If there are pending items but no processing, alert
        if ($pendingCount > 10 && $processedLastHour < $this->getProcessingRateMinPerHour()) {
            return [
                'status' => 'alert',
                'severity' => 'warning',
                'message' => sprintf(
                    'Processing rate critically low: %d processed/hour with %d pending',
                    $processedLastHour,
                    $pendingCount
                ),
                'processed_count' => $processedLastHour,
                'pending_count' => $pendingCount,
                'details' => [
                    'expected_min_rate' => $this->getProcessingRateMinPerHour(),
                    'estimated_clear_time' => $processedLastHour > 0
                        ? round($pendingCount / $processedLastHour) . ' hours'
                        : 'indefinite',
                ],
            ];
        }

        return [
            'status' => 'healthy',
            'message' => 'Processing rate normal',
            'processed_count' => $processedLastHour,
            'pending_count' => $pendingCount,
        ];
    }

    /**
     * Check for silent failures (items failing without logging).
     */
    private function checkSilentFailures(): array
    {
        // Find items with incremented attempts but no recent error log
        $recentlyRetried = DB::table('offline_queue')
            ->where('attempts', '>', 0)
            ->where('updated_at', '>=', now()->subHour())
            ->pluck('invoice_id');

        if ($recentlyRetried->isEmpty()) {
            return [
                'status' => 'healthy',
                'message' => 'No silent failures detected',
                'count' => 0,
            ];
        }

        // Check if these have corresponding audit logs
        $loggedFailures = DB::table('audit_logs')
            ->where('event', 'like', '%queue%fail%')
            ->where('created_at', '>=', now()->subHour())
            ->whereIn('auditable_id', $recentlyRetried)
            ->pluck('auditable_id');

        $silentFailures = $recentlyRetried->diff($loggedFailures);

        if ($silentFailures->isEmpty()) {
            return [
                'status' => 'healthy',
                'message' => 'All failures properly logged',
                'count' => 0,
            ];
        }

        return [
            'status' => 'alert',
            'severity' => 'warning',
            'message' => sprintf('%d items failed without proper logging', $silentFailures->count()),
            'count' => $silentFailures->count(),
            'details' => [
                'silent_invoice_ids' => $silentFailures->take(10)->toArray(),
                'total_retried' => $recentlyRetried->count(),
                'logged_failures' => $loggedFailures->count(),
            ],
        ];
    }

    /**
     * Store metrics for trending analysis.
     */
    private function storeMetrics(array $results): void
    {
        $metrics = [
            'timestamp' => now()->toIso8601String(),
            'queue_size' => DB::table('offline_queue')->where('state', 'pending')->count(),
            'stuck_count' => $results['checks']['stuck_items']['count'] ?? 0,
            'exhausted_count' => $results['checks']['retry_exhaustion']['count'] ?? 0,
            'processing_rate' => $results['checks']['processing_rate']['processed_count'] ?? 0,
            'overall_status' => $results['status'],
        ];

        // Store current metrics
        Cache::put(self::METRICS_KEY, $metrics, 7200);

        // Store hourly snapshot (for growth comparison)
        $currentHour = now()->format('Y-m-d-H');
        Cache::put(self::METRICS_KEY . ':hourly:' . $currentHour, $metrics, 86400);
        Cache::put(self::METRICS_KEY . ':hourly', $metrics, 7200);

        // Store in Redis for time-series (if available)
        try {
            Redis::lpush('queue_health:history', json_encode($metrics));
            Redis::ltrim('queue_health:history', 0, 1000); // Keep last 1000 entries
        } catch (\Exception $e) {
            // Redis unavailable, skip time-series
        }
    }

    /**
     * Process and send alerts.
     */
    private function processAlerts(array $alerts): void
    {
        foreach ($alerts as $alert) {
            $cooldownKey = self::ALERT_COOLDOWN_KEY . $alert['check'];

            // Check cooldown to prevent alert fatigue
            if (Cache::has($cooldownKey)) {
                continue;
            }

            // Set cooldown based on severity
            $cooldownMinutes = $alert['severity'] === 'critical' ? 5 : 30;
            Cache::put($cooldownKey, true, $cooldownMinutes * 60);

            // Log alert
            $logLevel = $alert['severity'] === 'critical' ? 'critical' : 'warning';
            Log::$logLevel('Queue health alert', [
                'check' => $alert['check'],
                'severity' => $alert['severity'],
                'message' => $alert['message'],
                'details' => $alert['details'],
            ]);

            // Here you would integrate with your alerting system:
            // - PagerDuty
            // - Slack
            // - Email
            // - SMS for critical alerts
            $this->sendAlert($alert);
        }
    }

    /**
     * Send alert to configured channels.
     */
    private function sendAlert(array $alert): void
    {
        // Store in database for audit
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => 'QueueHealth',
            'auditable_id' => $alert['check'],
            'event' => 'health_alert',
            'old_values' => json_encode([]),
            'new_values' => json_encode($alert),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => null,
            'user_agent' => 'QueueHealthMonitor',
            'tags' => json_encode(['queue', 'health', $alert['severity']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Integration points (implement based on your alerting stack):
        // $this->sendToPagerDuty($alert);
        // $this->sendToSlack($alert);
        // $this->sendEmail($alert);
    }

    /**
     * Get queue health dashboard data.
     */
    public function getDashboard(): array
    {
        $currentMetrics = Cache::get(self::METRICS_KEY, []);

        // Get historical data
        $history = [];
        try {
            $historyData = Redis::lrange('queue_health:history', 0, 24);
            $history = array_map(fn($item) => json_decode($item, true), $historyData);
        } catch (\Exception $e) {
            // Redis unavailable
        }

        // Get per-organization breakdown
        $byOrganization = DB::table('offline_queue')
            ->where('state', 'pending')
            ->selectRaw('organization_id, COUNT(*) as count, MIN(queued_at) as oldest')
            ->groupBy('organization_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return [
            'current' => $currentMetrics,
            'last_check' => Cache::get(self::LAST_CHECK_KEY),
            'history' => $history,
            'by_organization' => $byOrganization,
            'thresholds' => [
                'stuck_item_minutes' => $this->getStuckItemThresholdMinutes(),
                'max_retries' => $this->getMaxRetryCount(),
                'queue_growth_threshold' => $this->getQueueGrowthThreshold(),
                'min_processing_rate' => $this->getProcessingRateMinPerHour(),
            ],
        ];
    }

    /**
     * Manually requeue stuck items.
     */
    public function requeueStuckItems(int $limit = 100): array
    {
        $threshold = now()->subMinutes($this->getStuckItemThresholdMinutes());

        $stuckItems = DB::table('offline_queue')
            ->where('state', 'pending')
            ->where('queued_at', '<', $threshold)
            ->where('attempts', '<', $this->getMaxRetryCount())
            ->limit($limit)
            ->get();

        $requeued = 0;
        foreach ($stuckItems as $item) {
            DB::table('offline_queue')
                ->where('id', $item->id)
                ->update([
                    'next_attempt_at' => now(), // Reset to allow immediate retry
                    'updated_at' => now(),
                ]);
            $requeued++;
        }

        Log::info('Manually requeued stuck items', [
            'count' => $requeued,
        ]);

        return [
            'requeued' => $requeued,
            'requeued_at' => now()->toIso8601String(),
        ];
    }
}
