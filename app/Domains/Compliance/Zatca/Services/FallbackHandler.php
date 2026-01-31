<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\Models\InvoiceSubmission;
use App\Domains\Logging\Services\ComplianceLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Fallback Handler for ZATCA Compliance.
 *
 * Provides graceful degradation and recovery mechanisms for:
 * - Database failures
 * - Queue failures
 * - External API failures
 * - Logging failures
 *
 * POLICY: Never lose invoice data. Store locally if remote fails.
 */
class FallbackHandler
{
    private ComplianceLogger $logger;

    public function __construct(ComplianceLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Execute with database fallback.
     *
     * If database is unavailable, stores data in file-based cache
     * for later replay.
     */
    public function withDatabaseFallback(
        callable $operation,
        array $fallbackData,
        string $operationType
    ): mixed {
        try {
            return $operation();
        } catch (Throwable $e) {
            if ($this->isDatabaseError($e)) {
                $this->storeForLaterReplay($operationType, $fallbackData);
                $this->logger->error('Database operation failed, stored for replay', [
                    'operation' => $operationType,
                    'error' => $e->getMessage(),
                ]);

                // Return a safe default or throw a specific exception
                throw new \RuntimeException(
                    "Database temporarily unavailable. Data saved for replay. Error: {$e->getMessage()}",
                    503,
                    $e
                );
            }

            throw $e;
        }
    }

    /**
     * Execute with queue fallback.
     *
     * If queue is unavailable, processes synchronously or stores for later.
     */
    public function withQueueFallback(
        callable $queueOperation,
        callable $syncFallback,
        string $operationType
    ): mixed {
        try {
            // Check if queue is healthy
            if (!$this->isQueueHealthy()) {
                $this->logger->warning('Queue unhealthy, falling back to sync', [
                    'operation' => $operationType,
                ]);
                return $syncFallback();
            }

            return $queueOperation();
        } catch (Throwable $e) {
            if ($this->isQueueError($e)) {
                $this->logger->warning('Queue operation failed, falling back to sync', [
                    'operation' => $operationType,
                    'error' => $e->getMessage(),
                ]);

                try {
                    return $syncFallback();
                } catch (Throwable $syncError) {
                    $this->logger->error('Sync fallback also failed', [
                        'operation' => $operationType,
                        'queue_error' => $e->getMessage(),
                        'sync_error' => $syncError->getMessage(),
                    ]);
                    throw $syncError;
                }
            }

            throw $e;
        }
    }

    /**
     * Execute with external API fallback.
     *
     * Implements circuit breaker pattern with local caching.
     */
    public function withApiFallback(
        callable $apiOperation,
        ?callable $cacheFallback = null,
        string $serviceName = 'zatca'
    ): mixed {
        $cacheKey = "api_fallback:{$serviceName}:available";

        // Check if API was recently unavailable
        if (Cache::get($cacheKey) === false) {
            if ($cacheFallback !== null) {
                $this->logger->warning('API marked unavailable, using cache fallback', [
                    'service' => $serviceName,
                ]);
                return $cacheFallback();
            }

            throw new \RuntimeException("Service {$serviceName} is temporarily unavailable");
        }

        try {
            $result = $apiOperation();

            // Mark as available on success
            Cache::put($cacheKey, true, now()->addMinutes(5));

            return $result;
        } catch (Throwable $e) {
            if ($this->isApiError($e)) {
                // Mark as unavailable for a short period
                Cache::put($cacheKey, false, now()->addMinutes(1));

                $this->logger->error('API operation failed', [
                    'service' => $serviceName,
                    'error' => $e->getMessage(),
                ]);

                if ($cacheFallback !== null) {
                    return $cacheFallback();
                }
            }

            throw $e;
        }
    }

    /**
     * Execute with logging fallback.
     *
     * If primary logging fails, falls back to file or error_log.
     */
    public function withLoggingFallback(
        callable $logOperation,
        string $message,
        array $context
    ): void {
        try {
            $logOperation();
        } catch (Throwable $e) {
            // Try file fallback
            try {
                $fallbackPath = storage_path('logs/fallback.log');
                $logEntry = sprintf(
                    "[%s] %s | Context: %s | Original Error: %s\n",
                    now()->toIso8601String(),
                    $message,
                    json_encode($context),
                    $e->getMessage()
                );
                file_put_contents($fallbackPath, $logEntry, FILE_APPEND | LOCK_EX);
            } catch (Throwable $fileError) {
                // Last resort: PHP error_log
                error_log(sprintf(
                    '[FallbackHandler] Logging completely failed. Message: %s, Context: %s',
                    $message,
                    json_encode($context)
                ));
            }
        }
    }

    /**
     * Store data for later replay when primary storage fails.
     */
    public function storeForLaterReplay(string $operationType, array $data): string
    {
        $filename = sprintf(
            'replay_%s_%s_%s.json',
            $operationType,
            now()->format('Y-m-d_H-i-s'),
            uniqid()
        );

        $replayPath = storage_path('logs/replay');
        if (!is_dir($replayPath)) {
            mkdir($replayPath, 0755, true);
        }

        $filepath = $replayPath . '/' . $filename;

        $replayData = [
            'operation_type' => $operationType,
            'data' => $data,
            'created_at' => now()->toIso8601String(),
            'replayed' => false,
        ];

        file_put_contents($filepath, json_encode($replayData, JSON_PRETTY_PRINT));

        return $filepath;
    }

    /**
     * Replay stored operations after recovery.
     */
    public function replayStoredOperations(): array
    {
        $replayPath = storage_path('logs/replay');
        if (!is_dir($replayPath)) {
            return ['replayed' => 0, 'failed' => 0, 'total' => 0];
        }

        $files = glob($replayPath . '/replay_*.json');
        $results = ['replayed' => 0, 'failed' => 0, 'total' => count($files)];

        foreach ($files as $file) {
            try {
                $data = json_decode(file_get_contents($file), true);

                if ($data['replayed'] ?? false) {
                    continue;
                }

                $success = $this->replayOperation($data['operation_type'], $data['data']);

                if ($success) {
                    // Mark as replayed
                    $data['replayed'] = true;
                    $data['replayed_at'] = now()->toIso8601String();
                    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
                    $results['replayed']++;
                } else {
                    $results['failed']++;
                }
            } catch (Throwable $e) {
                $results['failed']++;
                $this->logger->error('Failed to replay operation', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Replay a specific operation.
     */
    private function replayOperation(string $operationType, array $data): bool
    {
        return match ($operationType) {
            'submission_create' => $this->replaySubmissionCreate($data),
            'state_log' => $this->replayStateLog($data),
            'webhook_dispatch' => $this->replayWebhookDispatch($data),
            default => false,
        };
    }

    /**
     * Replay a submission create operation.
     */
    private function replaySubmissionCreate(array $data): bool
    {
        try {
            DB::table('invoice_submissions')->insert($data);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Replay a state log operation.
     */
    private function replayStateLog(array $data): bool
    {
        try {
            DB::table('submission_state_logs')->insert($data);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Replay a webhook dispatch operation.
     */
    private function replayWebhookDispatch(array $data): bool
    {
        // Re-queue the webhook
        try {
            Queue::push('App\Domains\Webhook\Jobs\DeliverWebhook', $data);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Check if queue is healthy.
     */
    private function isQueueHealthy(): bool
    {
        try {
            $connection = config('queue.default');
            $size = Queue::size($connection === 'sync' ? null : 'default');
            return $size < 10000; // Consider unhealthy if queue is too backed up
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Check if exception is a database error.
     */
    private function isDatabaseError(Throwable $e): bool
    {
        $dbExceptions = [
            \PDOException::class,
            \Illuminate\Database\QueryException::class,
        ];

        foreach ($dbExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return str_contains($e->getMessage(), 'SQLSTATE') ||
               str_contains($e->getMessage(), 'Connection refused') ||
               str_contains($e->getMessage(), 'Too many connections');
    }

    /**
     * Check if exception is a queue error.
     */
    private function isQueueError(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'queue') ||
               str_contains($e->getMessage(), 'Redis') ||
               str_contains($e->getMessage(), 'BEANSTALKD') ||
               str_contains($e->getMessage(), 'SQS');
    }

    /**
     * Check if exception is an API error.
     */
    private function isApiError(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'cURL') ||
               str_contains($e->getMessage(), 'timeout') ||
               str_contains($e->getMessage(), 'Connection') ||
               str_contains($e->getMessage(), '503') ||
               str_contains($e->getMessage(), '502') ||
               str_contains($e->getMessage(), '504');
    }

    /**
     * Get pending replay count.
     */
    public function getPendingReplayCount(): int
    {
        $replayPath = storage_path('logs/replay');
        if (!is_dir($replayPath)) {
            return 0;
        }

        $count = 0;
        $files = glob($replayPath . '/replay_*.json');

        foreach ($files as $file) {
            try {
                $data = json_decode(file_get_contents($file), true);
                if (!($data['replayed'] ?? false)) {
                    $count++;
                }
            } catch (Throwable $e) {
                // Ignore malformed files
            }
        }

        return $count;
    }
}
