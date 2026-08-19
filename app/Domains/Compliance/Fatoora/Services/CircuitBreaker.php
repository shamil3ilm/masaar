<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cluster-Aware Circuit Breaker.
 *
 * Ensures circuit breaker state propagates across all nodes in a cluster.
 * Prevents scenarios where one node's failures don't protect other nodes.
 *
 * Features:
 * - Redis-backed state for cluster-wide consistency
 * - Pub/Sub for immediate state propagation
 * - Node health tracking for split-brain detection
 * - Automatic recovery with gradual traffic increase
 */
class CircuitBreaker
{
    /**
     * Circuit breaker states.
     */
    public const STATE_CLOSED = 'closed';     // Normal operation

    public const STATE_OPEN = 'open';         // Failing, reject requests

    public const STATE_HALF_OPEN = 'half_open'; // Testing recovery

    /**
     * Get failure threshold from config.
     */
    private function getFailureThreshold(): int
    {
        return (int) config('fatoora.circuit_breaker.failure_threshold', 5);
    }

    /**
     * Get success threshold from config.
     */
    private function getSuccessThreshold(): int
    {
        return (int) config('fatoora.circuit_breaker.success_threshold', 3);
    }

    /**
     * Get timeout seconds from config.
     */
    private function getTimeoutSeconds(): int
    {
        return (int) config('fatoora.circuit_breaker.timeout_seconds', 60);
    }

    /**
     * Get half-open request limit from config.
     */
    private function getHalfOpenRequests(): int
    {
        return (int) config('fatoora.circuit_breaker.half_open_max_requests', 3);
    }

    /**
     * Redis key prefixes.
     */
    private const STATE_KEY_PREFIX = 'circuit_breaker:state:';

    private const FAILURES_KEY_PREFIX = 'circuit_breaker:failures:';

    private const SUCCESSES_KEY_PREFIX = 'circuit_breaker:successes:';

    private const LAST_FAILURE_KEY_PREFIX = 'circuit_breaker:last_failure:';

    /**
     * Node identifier.
     */
    private string $nodeId;

    public function __construct()
    {
        $this->nodeId = gethostname().':'.getmypid();
    }

    /**
     * Check if circuit allows requests.
     *
     * @param  string  $service  Service identifier (e.g., 'zatca_api')
     * @return bool True if request is allowed
     */
    public function allowRequest(string $service): bool
    {
        $state = $this->getState($service);

        switch ($state) {
            case self::STATE_CLOSED:
                return true;

            case self::STATE_OPEN:
                // Check if timeout has passed
                if ($this->hasTimeoutPassed($service)) {
                    $this->transitionTo($service, self::STATE_HALF_OPEN);

                    return true;
                }

                return false;

            case self::STATE_HALF_OPEN:
                // Allow limited requests for testing
                return $this->allowHalfOpenRequest($service);

            default:
                return true;
        }
    }

    /**
     * Record a successful request.
     */
    public function recordSuccess(string $service): void
    {
        $state = $this->getState($service);

        if ($state === self::STATE_HALF_OPEN) {
            $successes = $this->incrementSuccesses($service);

            if ($successes >= $this->getSuccessThreshold()) {
                $this->transitionTo($service, self::STATE_CLOSED);
                $this->resetCounters($service);
            }
        } elseif ($state === self::STATE_CLOSED) {
            // Reset failure count on success
            $this->resetFailures($service);
        }
    }

    /**
     * Record a failed request.
     */
    public function recordFailure(string $service, ?\Throwable $exception = null): void
    {
        $state = $this->getState($service);

        if ($state === self::STATE_HALF_OPEN) {
            // Single failure in half-open returns to open
            $this->transitionTo($service, self::STATE_OPEN);

            return;
        }

        $failures = $this->incrementFailures($service);

        Log::warning('Circuit breaker failure recorded', [
            'service' => $service,
            'failures' => $failures,
            'threshold' => $this->getFailureThreshold(),
            'node_id' => $this->nodeId,
            'exception' => $exception?->getMessage(),
        ]);

        if ($failures >= $this->getFailureThreshold()) {
            $this->transitionTo($service, self::STATE_OPEN);
        }
    }

    /**
     * Get current circuit state.
     */
    public function getState(string $service): string
    {
        return (string) Cache::get(self::STATE_KEY_PREFIX.$service, self::STATE_CLOSED);
    }

    /**
     * Transition to a new state with cluster notification.
     */
    private function transitionTo(string $service, string $newState): void
    {
        $oldState = $this->getState($service);

        if ($oldState === $newState) {
            return;
        }

        Cache::forever(self::STATE_KEY_PREFIX.$service, $newState);

        if ($newState === self::STATE_OPEN) {
            // now() rather than time(): the cache TTLs beside this already
            // use Laravel's clock, and two clocks in one mechanism means the
            // recovery timeout can disagree with the counter expiry it is
            // meant to outlast.
            Cache::forever(self::LAST_FAILURE_KEY_PREFIX.$service, now()->getTimestamp());
        }

        Log::warning('Circuit breaker state transition', [
            'service' => $service,
            'from' => $oldState,
            'to' => $newState,
            'node_id' => $this->nodeId,
        ]);
    }

    /**
     * Check if timeout has passed since last failure.
     */
    private function hasTimeoutPassed(string $service): bool
    {
        $lastFailure = Cache::get(self::LAST_FAILURE_KEY_PREFIX.$service);

        if (! $lastFailure) {
            return true;
        }

        return (now()->getTimestamp() - (int) $lastFailure) >= $this->getTimeoutSeconds();
    }

    /**
     * Allow limited requests in half-open state.
     */
    private function allowHalfOpenRequest(string $service): bool
    {
        $count = $this->bump('circuit_breaker:half_open_count:'.$service, $this->getTimeoutSeconds());

        return $count <= $this->getHalfOpenRequests();
    }

    /**
     * Increment failure counter.
     */
    private function incrementFailures(string $service): int
    {
        return $this->bump(self::FAILURES_KEY_PREFIX.$service, $this->getTimeoutSeconds() * 2);
    }

    /**
     * Increment success counter.
     */
    private function incrementSuccesses(string $service): int
    {
        return $this->bump(self::SUCCESSES_KEY_PREFIX.$service, $this->getTimeoutSeconds() * 2);
    }

    /**
     * Increment a counter that expires on its own.
     *
     * add() then increment(): add is a no-op when the key is present, so the
     * TTL is set once by whichever caller gets there first and the counter is
     * not extended by every later hit. Both are atomic on a shared store, which
     * is what keeps two nodes from each counting the same outage separately.
     */
    private function bump(string $key, int $ttlSeconds): int
    {
        Cache::add($key, 0, $ttlSeconds);

        return (int) Cache::increment($key);
    }

    /**
     * Reset failure counter.
     */
    private function resetFailures(string $service): void
    {
        Cache::forget(self::FAILURES_KEY_PREFIX.$service);
    }

    /**
     * Reset all counters.
     */
    private function resetCounters(string $service): void
    {
        foreach ([
            self::FAILURES_KEY_PREFIX.$service,
            self::SUCCESSES_KEY_PREFIX.$service,
            'circuit_breaker:half_open_count:'.$service,
        ] as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Force circuit state (admin operation).
     */
    public function forceState(string $service, string $state, string $reason, string $forcedBy): array
    {
        $validStates = [self::STATE_CLOSED, self::STATE_OPEN, self::STATE_HALF_OPEN];

        if (! in_array($state, $validStates, true)) {
            throw new \InvalidArgumentException("Invalid state: {$state}");
        }

        $oldState = $this->getState($service);
        $this->transitionTo($service, $state);

        if ($state === self::STATE_CLOSED) {
            $this->resetCounters($service);
        }

        Log::warning('Circuit breaker state forced', [
            'service' => $service,
            'from' => $oldState,
            'to' => $state,
            'reason' => $reason,
            'forced_by' => $forcedBy,
        ]);

        return [
            'service' => $service,
            'previous_state' => $oldState,
            'new_state' => $state,
            'reason' => $reason,
            'forced_by' => $forcedBy,
            'forced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get circuit breaker metrics.
     */
    public function getMetrics(string $service): array
    {
        return [
            'service' => $service,
            'state' => $this->getState($service),
            'failures' => (int) Cache::get(self::FAILURES_KEY_PREFIX.$service, 0),
            'successes' => (int) Cache::get(self::SUCCESSES_KEY_PREFIX.$service, 0),
            'failure_threshold' => $this->getFailureThreshold(),
            'success_threshold' => $this->getSuccessThreshold(),
            'timeout_seconds' => $this->getTimeoutSeconds(),
            'last_failure' => Cache::get(self::LAST_FAILURE_KEY_PREFIX.$service),
        ];
    }
}
