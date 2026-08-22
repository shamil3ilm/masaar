<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ZATCA API Connectivity Checker.
 *
 * Monitors ZATCA API availability for:
 * - Auto-switching to offline mode when API is unavailable
 * - Pre-flight checks before submission
 * - Health monitoring and alerting
 *
 * Uses circuit breaker pattern to prevent hammering unavailable API.
 */
class Connectivity
{
    /**
     * Cache key for connectivity status.
     */
    private const CACHE_KEY = 'zatca:connectivity:status';

    /**
     * The name this service is tracked under in the shared circuit breaker.
     */
    private const SERVICE = 'zatca_api';

    /**
     * How long to cache connectivity status (seconds).
     */
    private const CACHE_TTL = 30;

    /**
     * Request timeout (seconds).
     */
    private const TIMEOUT = 10;

    public function __construct(
        private readonly CircuitBreaker $circuitBreaker,
    ) {}

    /**
     * Check ZATCA API connectivity.
     *
     * @return array{available: bool, latency_ms: ?int, reason: ?string, checked_at: string}
     */
    public function check(): array
    {
        // Check cache first
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        // Check circuit breaker
        if ($this->isCircuitOpen()) {
            $result = [
                'available' => false,
                'latency_ms' => null,
                'reason' => 'Circuit breaker open - too many recent failures',
                'checked_at' => now()->toIso8601String(),
                'circuit_state' => 'open',
            ];
            Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL);

            return $result;
        }

        // Perform actual connectivity check
        $result = $this->performCheck();

        // Update circuit breaker based on result
        $this->updateCircuitBreaker($result['available']);

        // Cache result
        Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Force refresh connectivity status (bypass cache).
     */
    public function forceCheck(): array
    {
        Cache::forget(self::CACHE_KEY);

        return $this->check();
    }

    /**
     * Check if ZATCA is available (simple boolean).
     */
    public function isAvailable(): bool
    {
        return $this->check()['available'];
    }

    /**
     * Check if we should use offline mode.
     */
    public function shouldUseOfflineMode(): bool
    {
        // Check if offline mode is globally enabled
        if (! config('fatoora.features.offline_mode', true)) {
            return false;
        }

        // Check connectivity
        return ! $this->isAvailable();
    }

    /**
     * Perform the actual connectivity check.
     */
    private function performCheck(): array
    {
        $baseUrl = FatooraConfig::getBaseUrl();
        $startTime = microtime(true);

        try {
            // Use a lightweight endpoint or health check
            // ZATCA doesn't have a dedicated health endpoint, so we'll use a HEAD request
            // to the base URL or a quick timeout test
            $response = Http::timeout(self::TIMEOUT)
                ->withOptions([
                    'connect_timeout' => 5,
                ])
                ->head($baseUrl);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            // Any response (even 4xx) means the server is reachable
            // 5xx errors indicate server issues
            $isAvailable = $response->status() < 500;

            $result = [
                'available' => $isAvailable,
                'latency_ms' => $latencyMs,
                'status_code' => $response->status(),
                'reason' => $isAvailable ? null : 'Server returned error: '.$response->status(),
                'checked_at' => now()->toIso8601String(),
                'circuit_state' => 'closed',
            ];

            if ($isAvailable) {
                Log::debug('ZATCA connectivity check passed', [
                    'latency_ms' => $latencyMs,
                    'status_code' => $response->status(),
                ]);
            } else {
                Log::warning('ZATCA connectivity check failed', [
                    'status_code' => $response->status(),
                    'latency_ms' => $latencyMs,
                ]);
            }

            return $result;

        } catch (ConnectionException $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::warning('ZATCA connectivity check failed - connection error', [
                'error' => $e->getMessage(),
                'latency_ms' => $latencyMs,
            ]);

            return [
                'available' => false,
                'latency_ms' => $latencyMs,
                'reason' => 'Connection failed: '.$e->getMessage(),
                'checked_at' => now()->toIso8601String(),
                'circuit_state' => 'closed',
            ];

        } catch (\Exception $e) {
            Log::error('ZATCA connectivity check error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'latency_ms' => null,
                'reason' => 'Check failed: '.$e->getMessage(),
                'checked_at' => now()->toIso8601String(),
                'circuit_state' => 'closed',
            ];
        }
    }

    /**
     * Check if circuit breaker is open.
     */
    private function isCircuitOpen(): bool
    {
        // fatoora.features.circuit_breaker was read nowhere, so setting
        // ZATCA_FEATURE_CIRCUIT_BREAKER=false turned nothing off. A breaker
        // that cannot be disabled is a problem during the incident where the
        // breaker itself is the thing misbehaving.
        if (! config('fatoora.features.circuit_breaker', true)) {
            return false;
        }

        return ! $this->circuitBreaker->allowRequest(self::SERVICE);
    }

    /**
     * Update circuit breaker based on result.
     */
    private function updateCircuitBreaker(bool $success): void
    {
        if ($success) {
            $this->circuitBreaker->recordSuccess(self::SERVICE);
        } else {
            $this->circuitBreaker->recordFailure(self::SERVICE);
        }
    }

    /**
     * Manually reset circuit breaker.
     */
    public function resetCircuitBreaker(): void
    {
        $this->circuitBreaker->forceState(self::SERVICE, 'closed', 'manual reset', 'operator');

        // Drop the cached verdict too, or the next check returns the stale
        // "circuit open" answer for up to CACHE_TTL after the reset.
        Cache::forget(self::CACHE_KEY);

        Log::info('ZATCA connectivity circuit breaker manually reset');
    }

    /**
     * Get detailed connectivity status for monitoring.
     */
    public function getDetailedStatus(): array
    {
        return [
            'connectivity' => $this->check(),
            // Read from the shared breaker, so every replica reports the same
            // state rather than its own local view of ZATCA's health.
            'circuit_breaker' => $this->circuitBreaker->getMetrics(self::SERVICE),
            'config' => [
                'base_url' => FatooraConfig::getBaseUrl(),
                'timeout_seconds' => self::TIMEOUT,
                'cache_ttl_seconds' => self::CACHE_TTL,
                'circuit_open_duration_seconds' => self::CIRCUIT_OPEN_DURATION,
            ],
        ];
    }
}
