<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker for ZATCA API.
 *
 * Implements the circuit breaker pattern to prevent cascading failures
 * when ZATCA API is unavailable or experiencing issues.
 *
 * States:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Circuit is tripped, requests fail immediately
 * - HALF_OPEN: Testing if service has recovered
 *
 * This addresses:
 * - ZATCA APIs slow or unavailable
 * - Peak invoice bursts
 * - Network latency across regions
 * - Graceful degradation
 */
class CircuitBreaker
{
    /**
     * Circuit states.
     */
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    /**
     * Cache key prefix.
     */
    private const CACHE_PREFIX = 'zatca:circuit_breaker:';

    /**
     * Circuit name (for multiple circuits).
     */
    private string $name;

    /**
     * Failure threshold before opening circuit.
     */
    private int $failureThreshold;

    /**
     * Timeout in seconds before trying half-open.
     */
    private int $timeout;

    /**
     * Sample size for success ratio calculation.
     */
    private int $sampleSize;

    public function __construct(
        string $name = 'zatca_api',
        ?int $failureThreshold = null,
        ?int $timeout = null,
        ?int $sampleSize = null
    ) {
        $this->name = $name;
        $this->failureThreshold = $failureThreshold ?? FatooraConfig::CIRCUIT_BREAKER_THRESHOLD;
        $this->timeout = $timeout ?? FatooraConfig::CIRCUIT_BREAKER_TIMEOUT;
        $this->sampleSize = $sampleSize ?? FatooraConfig::CIRCUIT_BREAKER_SAMPLE_SIZE;
    }

    /**
     * Execute a callable with circuit breaker protection.
     *
     * @template T
     * @param callable(): T $callback
     * @param callable(): T|null $fallback
     * @return T
     * @throws FatooraException
     */
    public function execute(callable $callback, ?callable $fallback = null): mixed
    {
        $state = $this->getState();

        // If circuit is open, fail fast or use fallback
        if ($state === self::STATE_OPEN) {
            Log::warning('Circuit breaker is OPEN, rejecting request', [
                'circuit' => $this->name,
                'opens_at' => $this->getOpenedAt(),
                'timeout' => $this->timeout,
            ]);

            if ($fallback !== null) {
                return $fallback();
            }

            throw new FatooraException(
                'ZATCA service is temporarily unavailable (circuit breaker open)',
                ErrorCode::ZATCA_SERVICE_UNAVAILABLE,
                ['circuit_state' => self::STATE_OPEN]
            );
        }

        // If half-open, allow limited requests
        if ($state === self::STATE_HALF_OPEN) {
            Log::info('Circuit breaker is HALF_OPEN, allowing test request', [
                'circuit' => $this->name,
            ]);
        }

        try {
            $result = $callback();

            // Success - record it
            $this->recordSuccess();

            // If half-open and successful, close the circuit
            if ($state === self::STATE_HALF_OPEN) {
                $this->close();
            }

            return $result;
        } catch (\Throwable $e) {
            // Record failure
            $this->recordFailure($e);

            // Check if we should open the circuit
            if ($this->shouldOpen()) {
                $this->open();
            }

            throw $e;
        }
    }

    /**
     * Get current circuit state.
     */
    public function getState(): string
    {
        $state = Cache::get($this->cacheKey('state'), self::STATE_CLOSED);

        // Check if we should transition from open to half-open
        if ($state === self::STATE_OPEN) {
            $openedAt = $this->getOpenedAt();
            if ($openedAt && (time() - $openedAt) >= $this->timeout) {
                $this->halfOpen();
                return self::STATE_HALF_OPEN;
            }
        }

        return $state;
    }

    /**
     * Record a successful request.
     */
    public function recordSuccess(): void
    {
        $failures = $this->getRecentFailures();

        // Add success marker
        $failures[] = [
            'success' => true,
            'timestamp' => time(),
        ];

        // Keep only recent samples
        $failures = array_slice($failures, -$this->sampleSize);

        Cache::put($this->cacheKey('failures'), $failures, now()->addHours(1));
    }

    /**
     * Record a failed request.
     */
    public function recordFailure(\Throwable $e): void
    {
        $failures = $this->getRecentFailures();

        $failures[] = [
            'success' => false,
            'timestamp' => time(),
            'error' => get_class($e),
            'message' => $e->getMessage(),
        ];

        // Keep only recent samples
        $failures = array_slice($failures, -$this->sampleSize);

        Cache::put($this->cacheKey('failures'), $failures, now()->addHours(1));

        Log::warning('Circuit breaker recorded failure', [
            'circuit' => $this->name,
            'error' => $e->getMessage(),
            'failure_count' => $this->getFailureCount(),
            'threshold' => $this->failureThreshold,
        ]);
    }

    /**
     * Check if circuit should open based on failure count.
     */
    private function shouldOpen(): bool
    {
        return $this->getFailureCount() >= $this->failureThreshold;
    }

    /**
     * Get count of recent consecutive failures.
     */
    public function getFailureCount(): int
    {
        $failures = $this->getRecentFailures();
        $count = 0;

        // Count consecutive failures from the end
        for ($i = count($failures) - 1; $i >= 0; $i--) {
            if ($failures[$i]['success']) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * Get recent failure records.
     */
    private function getRecentFailures(): array
    {
        return Cache::get($this->cacheKey('failures'), []);
    }

    /**
     * Open the circuit.
     */
    public function open(): void
    {
        Cache::put($this->cacheKey('state'), self::STATE_OPEN, now()->addHours(1));
        Cache::put($this->cacheKey('opened_at'), time(), now()->addHours(1));

        Log::error('Circuit breaker OPENED', [
            'circuit' => $this->name,
            'failure_count' => $this->getFailureCount(),
            'timeout' => $this->timeout,
        ]);
    }

    /**
     * Close the circuit.
     */
    public function close(): void
    {
        Cache::put($this->cacheKey('state'), self::STATE_CLOSED, now()->addHours(1));
        Cache::forget($this->cacheKey('opened_at'));
        Cache::forget($this->cacheKey('failures'));

        Log::info('Circuit breaker CLOSED', [
            'circuit' => $this->name,
        ]);
    }

    /**
     * Transition to half-open state.
     */
    public function halfOpen(): void
    {
        Cache::put($this->cacheKey('state'), self::STATE_HALF_OPEN, now()->addHours(1));

        Log::info('Circuit breaker HALF_OPEN', [
            'circuit' => $this->name,
        ]);
    }

    /**
     * Get when circuit was opened.
     */
    public function getOpenedAt(): ?int
    {
        return Cache::get($this->cacheKey('opened_at'));
    }

    /**
     * Force reset the circuit.
     */
    public function reset(): void
    {
        Cache::forget($this->cacheKey('state'));
        Cache::forget($this->cacheKey('opened_at'));
        Cache::forget($this->cacheKey('failures'));

        Log::info('Circuit breaker RESET', [
            'circuit' => $this->name,
        ]);
    }

    /**
     * Check if circuit is allowing requests.
     */
    public function isAllowing(): bool
    {
        return $this->getState() !== self::STATE_OPEN;
    }

    /**
     * Get circuit breaker status.
     */
    public function getStatus(): array
    {
        return [
            'name' => $this->name,
            'state' => $this->getState(),
            'failure_count' => $this->getFailureCount(),
            'failure_threshold' => $this->failureThreshold,
            'opened_at' => $this->getOpenedAt(),
            'timeout' => $this->timeout,
            'is_allowing' => $this->isAllowing(),
        ];
    }

    /**
     * Generate cache key.
     */
    private function cacheKey(string $suffix): string
    {
        return self::CACHE_PREFIX . $this->name . ':' . $suffix;
    }
}
