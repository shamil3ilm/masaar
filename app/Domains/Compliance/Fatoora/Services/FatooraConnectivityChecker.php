<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use Carbon\Carbon;
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
class FatooraConnectivityChecker
{
    /**
     * Cache key for connectivity status.
     */
    private const CACHE_KEY = 'zatca:connectivity:status';

    /**
     * Cache key for circuit breaker state.
     */
    private const CIRCUIT_BREAKER_KEY = 'zatca:connectivity:circuit';

    /**
     * How long to cache connectivity status (seconds).
     */
    private const CACHE_TTL = 30;

    /**
     * Circuit breaker open duration (seconds).
     */
    private const CIRCUIT_OPEN_DURATION = 60;

    /**
     * Number of failures before opening circuit.
     */
    private const FAILURE_THRESHOLD = 3;

    /**
     * Request timeout (seconds).
     */
    private const TIMEOUT = 10;

    public function __construct(
        private readonly ?ClusterCircuitBreaker $circuitBreaker = null,
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
        // Use cluster circuit breaker if available
        if ($this->circuitBreaker) {
            return $this->circuitBreaker->isOpen('zatca_api');
        }

        // Simple local circuit breaker
        $circuit = Cache::get(self::CIRCUIT_BREAKER_KEY, [
            'failures' => 0,
            'opened_at' => null,
        ]);

        if ($circuit['opened_at'] !== null) {
            // Check if circuit should be half-open (allow retry)
            $openedAt = Carbon::parse($circuit['opened_at']);
            if ($openedAt->addSeconds(self::CIRCUIT_OPEN_DURATION)->isPast()) {
                // Allow retry (half-open state)
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Update circuit breaker based on result.
     */
    private function updateCircuitBreaker(bool $success): void
    {
        // Use cluster circuit breaker if available
        if ($this->circuitBreaker) {
            if ($success) {
                $this->circuitBreaker->recordSuccess('zatca_api');
            } else {
                $this->circuitBreaker->recordFailure('zatca_api');
            }

            return;
        }

        // Simple local circuit breaker
        $circuit = Cache::get(self::CIRCUIT_BREAKER_KEY, [
            'failures' => 0,
            'opened_at' => null,
        ]);

        if ($success) {
            // Reset on success
            Cache::put(self::CIRCUIT_BREAKER_KEY, [
                'failures' => 0,
                'opened_at' => null,
            ], now()->addMinutes(10));
        } else {
            $circuit['failures']++;

            if ($circuit['failures'] >= self::FAILURE_THRESHOLD) {
                $circuit['opened_at'] = now()->toIso8601String();
                Log::warning('ZATCA connectivity circuit breaker opened', [
                    'failures' => $circuit['failures'],
                ]);
            }

            Cache::put(self::CIRCUIT_BREAKER_KEY, $circuit, now()->addMinutes(10));
        }
    }

    /**
     * Manually reset circuit breaker.
     */
    public function resetCircuitBreaker(): void
    {
        if ($this->circuitBreaker) {
            $this->circuitBreaker->reset('zatca_api');
        }

        Cache::forget(self::CIRCUIT_BREAKER_KEY);
        Cache::forget(self::CACHE_KEY);

        Log::info('ZATCA connectivity circuit breaker manually reset');
    }

    /**
     * Get detailed connectivity status for monitoring.
     */
    public function getDetailedStatus(): array
    {
        $connectivity = $this->check();

        $circuit = Cache::get(self::CIRCUIT_BREAKER_KEY, [
            'failures' => 0,
            'opened_at' => null,
        ]);

        return [
            'connectivity' => $connectivity,
            'circuit_breaker' => [
                'state' => $circuit['opened_at'] !== null ? 'open' : 'closed',
                'failure_count' => $circuit['failures'],
                'threshold' => self::FAILURE_THRESHOLD,
                'opened_at' => $circuit['opened_at'],
                'will_retry_at' => $circuit['opened_at']
                    ? Carbon::parse($circuit['opened_at'])
                        ->addSeconds(self::CIRCUIT_OPEN_DURATION)
                        ->toIso8601String()
                    : null,
            ],
            'config' => [
                'base_url' => FatooraConfig::getBaseUrl(),
                'timeout_seconds' => self::TIMEOUT,
                'cache_ttl_seconds' => self::CACHE_TTL,
                'circuit_open_duration_seconds' => self::CIRCUIT_OPEN_DURATION,
            ],
        ];
    }
}
