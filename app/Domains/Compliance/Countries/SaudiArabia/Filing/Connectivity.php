<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
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
    public function check(?string $environment = null): array
    {
        $environment ??= FatooraEnvironment::default();
        $cacheKey = self::CACHE_KEY.':'.$environment;

        // Check cache first
        $cached = Cache::get($cacheKey);
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
            Cache::put($cacheKey, $result, self::CACHE_TTL);

            return $result;
        }

        // Perform actual connectivity check
        $result = $this->reach(FatooraEnvironment::baseUrl($environment));

        // Update circuit breaker based on result
        $this->updateCircuitBreaker($result['available']);

        // Cache result
        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Force refresh connectivity status (bypass cache).
     */
    public function forceCheck(?string $environment = null): array
    {
        Cache::forget(self::CACHE_KEY.':'.($environment ?? FatooraEnvironment::default()));

        return $this->check($environment);
    }

    /**
     * Check if ZATCA is available (simple boolean).
     */
    public function isAvailable(?string $environment = null): bool
    {
        return $this->check($environment)['available'];
    }

    /**
     * Check if we should use offline mode.
     */
    public function shouldUseOfflineMode(?string $environment = null): bool
    {
        // Check if offline mode is globally enabled
        if (! config('fatoora.features.offline_mode', true)) {
            return false;
        }

        // Check connectivity
        return ! $this->isAvailable($environment);
    }

    /**
     * Whether a ZATCA gateway URL answers: the one rule for reachability.
     *
     * Any HTTP answer, whatever its status, is a gateway that is there; only
     * no answer - a connection that failed or timed out - is offline. Not
     * cached and not counted by the circuit breaker; check() is, for one
     * environment's gateway.
     *
     * @return array{available: bool, latency_ms: ?int, reason: ?string, checked_at: string, server_date?: ?string}
     */
    public function reach(string $baseUrl): array
    {
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

            // Any answer at all means the gateway is there. It has nothing
            // at the base path and answers HEAD there with 500 - the
            // developer portal did, while filing documents fine - so a 5xx
            // here put every invoice in the offline queue. Only no answer
            // (below) is offline; a filing that then fails on a real 5xx is
            // retried as one (FatooraClient::transient()).
            $isAvailable = true;

            $result = [
                'available' => $isAvailable,
                'latency_ms' => $latencyMs,
                'status_code' => $response->status(),
                // The gateway's clock, which platform:simulation-preflight
                // compares the server's with.
                'server_date' => $response->header('Date') ?: null,
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
        foreach (FatooraEnvironment::ALL as $environment) {
            Cache::forget(self::CACHE_KEY.':'.$environment);
        }

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
                // The breaker's own setting; this named a constant that was
                // never declared.
                'circuit_open_duration_seconds' => (int) config('fatoora.circuit_breaker.timeout_seconds', 60),
            ],
        ];
    }
}
