<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

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
        return (int) config('fatoora.cluster_circuit_breaker.failure_threshold', 5);
    }

    /**
     * Get success threshold from config.
     */
    private function getSuccessThreshold(): int
    {
        return (int) config('fatoora.cluster_circuit_breaker.success_threshold', 3);
    }

    /**
     * Get timeout seconds from config.
     */
    private function getTimeoutSeconds(): int
    {
        return (int) config('fatoora.cluster_circuit_breaker.timeout_seconds', 60);
    }

    /**
     * Get half-open request limit from config.
     */
    private function getHalfOpenRequests(): int
    {
        return (int) config('fatoora.cluster_circuit_breaker.half_open_max_requests', 3);
    }

    /**
     * Redis key prefixes.
     */
    private const STATE_KEY_PREFIX = 'circuit_breaker:state:';

    private const FAILURES_KEY_PREFIX = 'circuit_breaker:failures:';

    private const SUCCESSES_KEY_PREFIX = 'circuit_breaker:successes:';

    private const LAST_FAILURE_KEY_PREFIX = 'circuit_breaker:last_failure:';

    private const NODES_KEY_PREFIX = 'circuit_breaker:nodes:';

    private const PUBSUB_CHANNEL = 'circuit_breaker:events';

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

        $this->registerNodeHealth($service, true);
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

        $this->registerNodeHealth($service, false);
    }

    /**
     * Get current circuit state.
     */
    public function getState(string $service): string
    {
        $state = Redis::get(self::STATE_KEY_PREFIX.$service);

        return $state ?: self::STATE_CLOSED;
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

        // Store new state
        Redis::set(self::STATE_KEY_PREFIX.$service, $newState);

        if ($newState === self::STATE_OPEN) {
            Redis::set(
                self::LAST_FAILURE_KEY_PREFIX.$service,
                time()
            );
        }

        // Publish state change to all nodes
        $this->publishStateChange($service, $oldState, $newState);

        Log::warning('Circuit breaker state transition', [
            'service' => $service,
            'from' => $oldState,
            'to' => $newState,
            'node_id' => $this->nodeId,
        ]);
    }

    /**
     * Publish state change via Redis Pub/Sub.
     */
    private function publishStateChange(string $service, string $oldState, string $newState): void
    {
        $event = [
            'type' => 'state_change',
            'service' => $service,
            'old_state' => $oldState,
            'new_state' => $newState,
            'node_id' => $this->nodeId,
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            Redis::publish(self::PUBSUB_CHANNEL, json_encode($event));
        } catch (\Exception $e) {
            Log::error('Failed to publish circuit breaker event', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if timeout has passed since last failure.
     */
    private function hasTimeoutPassed(string $service): bool
    {
        $lastFailure = Redis::get(self::LAST_FAILURE_KEY_PREFIX.$service);

        if (! $lastFailure) {
            return true;
        }

        return (time() - (int) $lastFailure) >= $this->getTimeoutSeconds();
    }

    /**
     * Allow limited requests in half-open state.
     */
    private function allowHalfOpenRequest(string $service): bool
    {
        // Use Redis to coordinate half-open request limit across cluster
        $key = 'circuit_breaker:half_open_count:'.$service;
        $count = Redis::incr($key);

        if ($count === 1) {
            // First request sets expiry
            Redis::expire($key, $this->getTimeoutSeconds());
        }

        return $count <= $this->getHalfOpenRequests();
    }

    /**
     * Increment failure counter.
     */
    private function incrementFailures(string $service): int
    {
        $key = self::FAILURES_KEY_PREFIX.$service;
        $count = Redis::incr($key);

        // Set expiry to reset after timeout
        Redis::expire($key, $this->getTimeoutSeconds() * 2);

        return (int) $count;
    }

    /**
     * Increment success counter.
     */
    private function incrementSuccesses(string $service): int
    {
        $key = self::SUCCESSES_KEY_PREFIX.$service;
        $count = Redis::incr($key);

        Redis::expire($key, $this->getTimeoutSeconds() * 2);

        return (int) $count;
    }

    /**
     * Reset failure counter.
     */
    private function resetFailures(string $service): void
    {
        Redis::del(self::FAILURES_KEY_PREFIX.$service);
    }

    /**
     * Reset all counters.
     */
    private function resetCounters(string $service): void
    {
        Redis::del([
            self::FAILURES_KEY_PREFIX.$service,
            self::SUCCESSES_KEY_PREFIX.$service,
            'circuit_breaker:half_open_count:'.$service,
        ]);
    }

    /**
     * Register node health for split-brain detection.
     */
    private function registerNodeHealth(string $service, bool $healthy): void
    {
        $key = self::NODES_KEY_PREFIX.$service;

        Redis::hset($key, $this->nodeId, json_encode([
            'healthy' => $healthy,
            'last_seen' => time(),
        ]));

        // Expire stale node entries after 5 minutes
        Redis::expire($key, 300);
    }

    /**
     * Get cluster health status.
     */
    public function getClusterHealth(string $service): array
    {
        $key = self::NODES_KEY_PREFIX.$service;
        $nodes = Redis::hgetall($key);

        $healthyNodes = 0;
        $unhealthyNodes = 0;
        $staleNodes = 0;
        $nodeDetails = [];

        foreach ($nodes as $nodeId => $data) {
            $nodeData = json_decode($data, true);
            $isStale = (time() - ($nodeData['last_seen'] ?? 0)) > 60;

            if ($isStale) {
                $staleNodes++;
            } elseif ($nodeData['healthy'] ?? false) {
                $healthyNodes++;
            } else {
                $unhealthyNodes++;
            }

            $nodeDetails[$nodeId] = [
                'healthy' => $nodeData['healthy'] ?? false,
                'stale' => $isStale,
                'last_seen' => $nodeData['last_seen'] ?? null,
            ];
        }

        return [
            'service' => $service,
            'state' => $this->getState($service),
            'healthy_nodes' => $healthyNodes,
            'unhealthy_nodes' => $unhealthyNodes,
            'stale_nodes' => $staleNodes,
            'total_nodes' => count($nodes),
            'split_brain_risk' => $unhealthyNodes > 0 && $healthyNodes > 0,
            'nodes' => $nodeDetails,
        ];
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
            'failures' => (int) Redis::get(self::FAILURES_KEY_PREFIX.$service),
            'successes' => (int) Redis::get(self::SUCCESSES_KEY_PREFIX.$service),
            'failure_threshold' => $this->getFailureThreshold(),
            'success_threshold' => $this->getSuccessThreshold(),
            'timeout_seconds' => $this->getTimeoutSeconds(),
            'last_failure' => Redis::get(self::LAST_FAILURE_KEY_PREFIX.$service),
            'cluster_health' => $this->getClusterHealth($service),
        ];
    }

    /**
     * Subscribe to circuit breaker events (for monitoring).
     * Note: This blocks and should run in a separate process.
     */
    public function subscribeToEvents(callable $callback): void
    {
        Redis::subscribe([self::PUBSUB_CHANNEL], function ($message) use ($callback) {
            $event = json_decode($message, true);
            $callback($event);
        });
    }
}
