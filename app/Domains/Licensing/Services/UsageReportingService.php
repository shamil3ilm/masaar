<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Usage Reporting Service.
 *
 * Reports platform usage metrics to the license server for billing/monitoring.
 */
class UsageReportingService
{
    private ?string $licenseServerUrl;
    private ?string $licenseKey;

    /**
     * Cache key for tracking reported metrics.
     */
    private const CACHE_KEY_LAST_REPORT = 'usage_report_last_metrics';

    public function __construct()
    {
        $this->licenseServerUrl = config('platform-license.server_url');
        $this->licenseKey = config('platform-license.key');
    }

    /**
     * Report current usage metrics to the license server.
     */
    public function report(): array
    {
        if (!$this->licenseServerUrl || !$this->licenseKey) {
            return [
                'success' => false,
                'message' => 'License server URL or key not configured',
            ];
        }

        try {
            $metrics = $this->collectMetrics();

            $response = Http::timeout(10)
                ->post($this->licenseServerUrl . '/usage', [
                    'license_key' => $this->licenseKey,
                    'metrics' => $metrics,
                ]);

            if ($response->successful()) {
                // Store last reported metrics for delta calculation
                Cache::put(self::CACHE_KEY_LAST_REPORT, [
                    'metrics' => $metrics,
                    'reported_at' => now()->toISOString(),
                ], now()->addDay());

                Log::info('Usage metrics reported successfully', $metrics);

                return [
                    'success' => true,
                    'message' => 'Usage reported',
                    'metrics' => $metrics,
                ];
            }

            Log::warning('Usage report failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to report usage',
                'status' => $response->status(),
            ];

        } catch (\Exception $e) {
            Log::error('Usage reporting error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Collect current usage metrics.
     */
    public function collectMetrics(): array
    {
        $lastReport = Cache::get(self::CACHE_KEY_LAST_REPORT);
        $lastMetrics = $lastReport['metrics'] ?? null;

        // Get current totals
        $currentTotals = $this->getCurrentTotals();

        // Calculate deltas since last report (for incremental metrics)
        $invoicesCreated = $currentTotals['total_invoices'];
        $invoicesSubmitted = $currentTotals['submitted_invoices'];
        $invoicesCleared = $currentTotals['cleared_invoices'];
        $invoicesReported = $currentTotals['reported_invoices'];

        if ($lastMetrics) {
            $invoicesCreated = max(0, $currentTotals['total_invoices'] - ($lastMetrics['total_invoices'] ?? 0));
            $invoicesSubmitted = max(0, $currentTotals['submitted_invoices'] - ($lastMetrics['total_submitted'] ?? 0));
            $invoicesCleared = max(0, $currentTotals['cleared_invoices'] - ($lastMetrics['total_cleared'] ?? 0));
            $invoicesReported = max(0, $currentTotals['reported_invoices'] - ($lastMetrics['total_reported'] ?? 0));
        }

        return [
            // Delta metrics (since last report)
            'invoices_created' => $invoicesCreated,
            'invoices_submitted' => $invoicesSubmitted,
            'invoices_cleared' => $invoicesCleared,
            'invoices_reported' => $invoicesReported,

            // Snapshot metrics (current state)
            'organizations_count' => $currentTotals['organizations_count'],
            'users_count' => $currentTotals['users_count'],
            'api_calls' => $this->getApiCallCount(),

            // For next delta calculation
            'total_invoices' => $currentTotals['total_invoices'],
            'total_submitted' => $currentTotals['submitted_invoices'],
            'total_cleared' => $currentTotals['cleared_invoices'],
            'total_reported' => $currentTotals['reported_invoices'],

            // Metadata
            'reported_at' => now()->toISOString(),
            'period_start' => $lastReport['reported_at'] ?? now()->startOfDay()->toISOString(),
        ];
    }

    /**
     * Get current totals from database.
     */
    private function getCurrentTotals(): array
    {
        return [
            'total_invoices' => DB::table('invoices')->count(),
            'submitted_invoices' => DB::table('invoice_submissions')->count(),
            'cleared_invoices' => DB::table('invoice_submissions')
                ->where('clearance_status', 'CLEARED')
                ->count(),
            'reported_invoices' => DB::table('invoice_submissions')
                ->where('reporting_status', 'REPORTED')
                ->count(),
            'organizations_count' => DB::table('organizations')->count(),
            'users_count' => DB::table('users')->count(),
        ];
    }

    /**
     * Get API call count (from cache/logs).
     */
    private function getApiCallCount(): int
    {
        // Get from rate limiter cache or return 0
        $cacheKey = 'api_calls_count_' . now()->format('Y-m-d');
        return (int) Cache::get($cacheKey, 0);
    }

    /**
     * Increment API call counter (call from middleware).
     */
    public static function incrementApiCalls(): void
    {
        $cacheKey = 'api_calls_count_' . now()->format('Y-m-d');
        Cache::increment($cacheKey);

        // Ensure it expires at end of day
        if (Cache::get($cacheKey) === 1) {
            Cache::put($cacheKey, 1, now()->endOfDay());
        }
    }
}
