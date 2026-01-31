<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\Config\ZatcaConfig;
use App\Domains\Compliance\Zatca\Enums\ErrorCode;
use App\Domains\Compliance\Zatca\Exceptions\ZatcaException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Back-Pressure Manager for ERP Integration.
 *
 * Problem: ERPs batch-process invoices, causing burst traffic.
 * Month-end: 10,000 invoices in 5 minutes.
 *
 * This service provides:
 * - Adaptive rate limiting based on ZATCA API health
 * - Queue depth monitoring and alerts
 * - ERP-specific throttling
 * - Graceful degradation with backoff signals
 *
 * Pattern: Token bucket with adaptive refill rate.
 */
class BackPressureManager
{
    /**
     * Cache key prefixes.
     */
    private const BUCKET_PREFIX = 'zatca:backpressure:bucket:';
    private const METRICS_PREFIX = 'zatca:backpressure:metrics:';
    private const CONFIG_PREFIX = 'zatca:backpressure:config:';

    /**
     * Get default tokens per second from config.
     */
    private function getDefaultTokensPerSecond(): int
    {
        return (int) config('zatca.back_pressure.tokens_per_second', 10);
    }

    /**
     * Get default bucket size from config.
     */
    private function getDefaultBucketSize(): int
    {
        return (int) config('zatca.back_pressure.bucket_size', 100);
    }

    /**
     * Get minimum tokens per second from config.
     */
    private function getDefaultMinTokensPerSecond(): int
    {
        return (int) config('zatca.back_pressure.min_tokens', 1);
    }

    /**
     * Get burst allowance from config.
     */
    private function getDefaultBurstAllowance(): int
    {
        return (int) config('zatca.back_pressure.burst_allowance', 50);
    }

    /**
     * Pressure levels.
     */
    public const PRESSURE_NONE = 'none';
    public const PRESSURE_LOW = 'low';
    public const PRESSURE_MEDIUM = 'medium';
    public const PRESSURE_HIGH = 'high';
    public const PRESSURE_CRITICAL = 'critical';

    public function __construct(
        private readonly CircuitBreaker $circuitBreaker,
        private readonly KillSwitchManager $killSwitchManager,
    ) {
    }

    /**
     * Attempt to acquire a token for processing.
     * Returns immediately with success/failure.
     *
     * @param string $organizationId
     * @param int $tokens Number of tokens to acquire (default 1)
     * @return array{allowed: bool, wait_ms: int, pressure: string, tokens_remaining: int}
     */
    public function tryAcquire(string $organizationId, int $tokens = 1): array
    {
        $bucket = $this->getBucket($organizationId);
        $config = $this->getConfig($organizationId);

        // Refill tokens based on time elapsed
        $this->refillBucket($bucket, $config);

        // Check if we have enough tokens
        if ($bucket['tokens'] >= $tokens) {
            $bucket['tokens'] -= $tokens;
            $bucket['last_acquire'] = microtime(true);
            $this->saveBucket($organizationId, $bucket);

            $this->recordMetric($organizationId, 'acquired', $tokens);

            return [
                'allowed' => true,
                'wait_ms' => 0,
                'pressure' => $this->calculatePressure($bucket, $config),
                'tokens_remaining' => (int) $bucket['tokens'],
            ];
        }

        // Calculate wait time for tokens to become available
        $tokensNeeded = $tokens - $bucket['tokens'];
        $waitSeconds = $tokensNeeded / $config['tokens_per_second'];
        $waitMs = (int) ceil($waitSeconds * 1000);

        $this->recordMetric($organizationId, 'throttled', $tokens);

        return [
            'allowed' => false,
            'wait_ms' => $waitMs,
            'pressure' => $this->calculatePressure($bucket, $config),
            'tokens_remaining' => (int) $bucket['tokens'],
            'retry_after' => $waitMs,
        ];
    }

    /**
     * Acquire tokens or throw exception.
     *
     * @throws ZatcaException if rate limited
     */
    public function acquire(string $organizationId, int $tokens = 1): void
    {
        $result = $this->tryAcquire($organizationId, $tokens);

        if (!$result['allowed']) {
            throw new ZatcaException(
                'Rate limited: too many requests',
                ErrorCode::RATE_LIMIT_EXCEEDED,
                [
                    'retry_after_ms' => $result['wait_ms'],
                    'pressure' => $result['pressure'],
                    'tokens_remaining' => $result['tokens_remaining'],
                ]
            );
        }
    }

    /**
     * Acquire tokens with blocking wait.
     *
     * @param string $organizationId
     * @param int $tokens
     * @param int $maxWaitMs Maximum time to wait (0 = no limit)
     * @return bool True if acquired, false if timeout
     */
    public function acquireWithWait(string $organizationId, int $tokens = 1, int $maxWaitMs = 5000): bool
    {
        $startTime = microtime(true) * 1000;

        while (true) {
            $result = $this->tryAcquire($organizationId, $tokens);

            if ($result['allowed']) {
                return true;
            }

            $elapsed = (microtime(true) * 1000) - $startTime;
            if ($maxWaitMs > 0 && $elapsed >= $maxWaitMs) {
                return false;
            }

            // Sleep for the smaller of: wait time or remaining time
            $sleepMs = min($result['wait_ms'], $maxWaitMs - (int) $elapsed);
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
    }

    /**
     * Adjust rate based on ZATCA API health.
     * Called after each ZATCA API call.
     */
    public function adjustRate(string $organizationId, bool $success, int $responseTimeMs): void
    {
        $config = $this->getConfig($organizationId);
        $metrics = $this->getMetrics($organizationId);

        // Track recent response times
        $metrics['response_times'][] = $responseTimeMs;
        if (count($metrics['response_times']) > 100) {
            array_shift($metrics['response_times']);
        }

        // Track success rate
        $metrics['recent_calls'][] = $success ? 1 : 0;
        if (count($metrics['recent_calls']) > 100) {
            array_shift($metrics['recent_calls']);
        }

        // Calculate metrics
        $avgResponseTime = array_sum($metrics['response_times']) / count($metrics['response_times']);
        $successRate = array_sum($metrics['recent_calls']) / count($metrics['recent_calls']);

        // Adaptive rate adjustment
        $newRate = $config['tokens_per_second'];

        if ($successRate < 0.8 || $avgResponseTime > 5000) {
            // Poor health: reduce rate
            $newRate = max(
                $config['min_tokens_per_second'],
                $config['tokens_per_second'] * 0.8
            );
            Log::warning('BackPressure: Reducing rate due to API health', [
                'organization_id' => $organizationId,
                'success_rate' => $successRate,
                'avg_response_time' => $avgResponseTime,
                'old_rate' => $config['tokens_per_second'],
                'new_rate' => $newRate,
            ]);
        } elseif ($successRate > 0.95 && $avgResponseTime < 2000) {
            // Good health: gradually increase rate
            $newRate = min(
                $config['max_tokens_per_second'],
                $config['tokens_per_second'] * 1.1
            );
        }

        $config['tokens_per_second'] = $newRate;
        $this->saveConfig($organizationId, $config);
        $this->saveMetrics($organizationId, $metrics);
    }

    /**
     * Get current back-pressure status.
     */
    public function getStatus(string $organizationId): array
    {
        $bucket = $this->getBucket($organizationId);
        $config = $this->getConfig($organizationId);
        $metrics = $this->getMetrics($organizationId);

        $this->refillBucket($bucket, $config);

        $avgResponseTime = count($metrics['response_times']) > 0
            ? array_sum($metrics['response_times']) / count($metrics['response_times'])
            : 0;

        $successRate = count($metrics['recent_calls']) > 0
            ? array_sum($metrics['recent_calls']) / count($metrics['recent_calls'])
            : 1;

        return [
            'organization_id' => $organizationId,
            'pressure' => $this->calculatePressure($bucket, $config),
            'tokens_available' => (int) $bucket['tokens'],
            'bucket_size' => $config['bucket_size'],
            'tokens_per_second' => $config['tokens_per_second'],
            'api_health' => [
                'success_rate' => round($successRate * 100, 2),
                'avg_response_time_ms' => (int) $avgResponseTime,
            ],
            'throttling_active' => $bucket['tokens'] < $config['bucket_size'] * 0.2,
            'metrics' => [
                'acquired_last_minute' => $metrics['acquired_last_minute'] ?? 0,
                'throttled_last_minute' => $metrics['throttled_last_minute'] ?? 0,
            ],
        ];
    }

    /**
     * Set custom rate limit for an organization.
     */
    public function setRateLimit(
        string $organizationId,
        int $tokensPerSecond,
        int $bucketSize,
        ?string $setBy = null
    ): void {
        $config = $this->getConfig($organizationId);

        $config['tokens_per_second'] = $tokensPerSecond;
        $config['max_tokens_per_second'] = max($tokensPerSecond, $config['max_tokens_per_second'] ?? $tokensPerSecond);
        $config['bucket_size'] = $bucketSize;
        $config['custom_limit_set_by'] = $setBy;
        $config['custom_limit_set_at'] = now()->toIso8601String();

        $this->saveConfig($organizationId, $config);

        Log::info('BackPressure: Custom rate limit set', [
            'organization_id' => $organizationId,
            'tokens_per_second' => $tokensPerSecond,
            'bucket_size' => $bucketSize,
            'set_by' => $setBy,
        ]);
    }

    /**
     * Reset rate limit to defaults.
     */
    public function resetRateLimit(string $organizationId): void
    {
        Cache::forget(self::CONFIG_PREFIX . $organizationId);
        Cache::forget(self::BUCKET_PREFIX . $organizationId);

        Log::info('BackPressure: Rate limit reset to defaults', [
            'organization_id' => $organizationId,
        ]);
    }

    /**
     * Estimate time to process a batch of invoices.
     */
    public function estimateBatchProcessingTime(string $organizationId, int $invoiceCount): array
    {
        $config = $this->getConfig($organizationId);
        $bucket = $this->getBucket($organizationId);
        $this->refillBucket($bucket, $config);

        // Calculate time needed
        $tokensAvailable = $bucket['tokens'];
        $tokensNeeded = max(0, $invoiceCount - $tokensAvailable);
        $secondsForTokens = $tokensNeeded / $config['tokens_per_second'];

        // Add estimated processing time per invoice
        $metrics = $this->getMetrics($organizationId);
        $avgProcessingTime = count($metrics['response_times']) > 0
            ? array_sum($metrics['response_times']) / count($metrics['response_times'])
            : 3000; // Default 3 seconds

        $totalProcessingSeconds = ($invoiceCount * $avgProcessingTime) / 1000;

        return [
            'invoice_count' => $invoiceCount,
            'tokens_available_now' => (int) $tokensAvailable,
            'rate_limit_wait_seconds' => (int) ceil($secondsForTokens),
            'processing_time_seconds' => (int) ceil($totalProcessingSeconds),
            'total_estimated_seconds' => (int) ceil($secondsForTokens + $totalProcessingSeconds),
            'recommendation' => $this->getBatchRecommendation($invoiceCount, $config),
        ];
    }

    /**
     * Get recommendation for batch processing.
     */
    private function getBatchRecommendation(int $invoiceCount, array $config): string
    {
        $optimalBatchSize = $config['bucket_size'];

        if ($invoiceCount <= $optimalBatchSize) {
            return 'Batch size is optimal for immediate processing';
        }

        $suggestedBatches = (int) ceil($invoiceCount / $optimalBatchSize);
        $intervalSeconds = $optimalBatchSize / $config['tokens_per_second'];

        return sprintf(
            'Consider splitting into %d batches of %d invoices, with %d second intervals',
            $suggestedBatches,
            $optimalBatchSize,
            (int) $intervalSeconds
        );
    }

    /**
     * Calculate current pressure level.
     */
    private function calculatePressure(array $bucket, array $config): string
    {
        $utilizationPercent = (1 - ($bucket['tokens'] / $config['bucket_size'])) * 100;

        if ($utilizationPercent < 20) {
            return self::PRESSURE_NONE;
        }
        if ($utilizationPercent < 50) {
            return self::PRESSURE_LOW;
        }
        if ($utilizationPercent < 75) {
            return self::PRESSURE_MEDIUM;
        }
        if ($utilizationPercent < 90) {
            return self::PRESSURE_HIGH;
        }

        return self::PRESSURE_CRITICAL;
    }

    /**
     * Get token bucket for organization.
     */
    private function getBucket(string $organizationId): array
    {
        return Cache::get(self::BUCKET_PREFIX . $organizationId, [
            'tokens' => $this->getDefaultBucketSize(),
            'last_refill' => microtime(true),
            'last_acquire' => null,
        ]);
    }

    /**
     * Save token bucket.
     */
    private function saveBucket(string $organizationId, array $bucket): void
    {
        Cache::put(self::BUCKET_PREFIX . $organizationId, $bucket, 3600);
    }

    /**
     * Refill bucket based on elapsed time.
     */
    private function refillBucket(array &$bucket, array $config): void
    {
        $now = microtime(true);
        $elapsed = $now - $bucket['last_refill'];
        $tokensToAdd = $elapsed * $config['tokens_per_second'];

        $bucket['tokens'] = min(
            $config['bucket_size'],
            $bucket['tokens'] + $tokensToAdd
        );
        $bucket['last_refill'] = $now;
    }

    /**
     * Get configuration for organization.
     */
    private function getConfig(string $organizationId): array
    {
        return Cache::get(self::CONFIG_PREFIX . $organizationId, [
            'tokens_per_second' => $this->getDefaultTokensPerSecond(),
            'max_tokens_per_second' => $this->getDefaultTokensPerSecond() * 2,
            'min_tokens_per_second' => $this->getDefaultMinTokensPerSecond(),
            'bucket_size' => $this->getDefaultBucketSize(),
            'burst_allowance' => $this->getDefaultBurstAllowance(),
        ]);
    }

    /**
     * Save configuration.
     */
    private function saveConfig(string $organizationId, array $config): void
    {
        Cache::put(self::CONFIG_PREFIX . $organizationId, $config, 86400);
    }

    /**
     * Get metrics for organization.
     */
    private function getMetrics(string $organizationId): array
    {
        return Cache::get(self::METRICS_PREFIX . $organizationId, [
            'response_times' => [],
            'recent_calls' => [],
            'acquired_last_minute' => 0,
            'throttled_last_minute' => 0,
        ]);
    }

    /**
     * Save metrics.
     */
    private function saveMetrics(string $organizationId, array $metrics): void
    {
        Cache::put(self::METRICS_PREFIX . $organizationId, $metrics, 3600);
    }

    /**
     * Record a metric event.
     */
    private function recordMetric(string $organizationId, string $type, int $count): void
    {
        $key = self::METRICS_PREFIX . $organizationId . ':' . $type . ':' . date('YmdHi');
        Cache::increment($key, $count);
        Cache::expire($key, 120); // Keep for 2 minutes
    }
}
