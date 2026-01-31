<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Usage Metering Service.
 *
 * Tracks API usage, enforces rate limits, and manages quotas.
 * Uses Redis for real-time rate limiting with DB fallback.
 */
class UsageMeteringService
{
    /**
     * Record an API call.
     */
    public function recordApiCall(License $license, bool $isError = false): void
    {
        $today = now()->toDateString();
        $month = now()->format('Y-m');

        // Update or create daily usage record
        LicenseUsage::updateOrCreate(
            [
                'license_id' => $license->id,
                'usage_date' => $today,
            ],
            [
                'usage_month' => $month,
            ]
        );

        // Increment counters
        DB::table('license_usage')
            ->where('license_id', $license->id)
            ->where('usage_date', $today)
            ->increment('api_calls');

        if ($isError) {
            DB::table('license_usage')
                ->where('license_id', $license->id)
                ->where('usage_date', $today)
                ->increment('api_errors');
        }
    }

    /**
     * Record an invoice submission.
     */
    public function recordInvoiceSubmission(
        License $license,
        bool $cleared = false,
        bool $reported = false,
        bool $failed = false,
        float $invoiceValue = 0.0
    ): void {
        $today = now()->toDateString();
        $month = now()->format('Y-m');

        // Ensure daily record exists
        LicenseUsage::firstOrCreate(
            [
                'license_id' => $license->id,
                'usage_date' => $today,
            ],
            [
                'usage_month' => $month,
            ]
        );

        // Increment counters
        $query = DB::table('license_usage')
            ->where('license_id', $license->id)
            ->where('usage_date', $today);

        $query->increment('invoices_submitted');

        if ($cleared) {
            $query->increment('invoices_cleared');
        }
        if ($reported) {
            $query->increment('invoices_reported');
        }
        if ($failed) {
            $query->increment('invoices_failed');
        }
        if ($invoiceValue > 0) {
            $query->increment('invoice_total_value', $invoiceValue);
        }
    }

    /**
     * Check and enforce rate limit.
     *
     * @throws LicenseException
     */
    public function checkRateLimit(License $license, string $limitType = 'api'): void
    {
        $limits = match ($limitType) {
            'api' => [
                'per_minute' => $license->max_api_calls_per_minute,
                'per_day' => $license->max_api_calls_per_day,
            ],
            default => throw new \InvalidArgumentException("Unknown limit type: {$limitType}"),
        };

        // Check per-minute limit using Redis
        $this->checkWindowLimit(
            $license,
            'minute',
            now()->format('Y-m-d-H-i'),
            $limits['per_minute'],
            60
        );

        // Check per-day limit
        $this->checkWindowLimit(
            $license,
            'day',
            now()->format('Y-m-d'),
            $limits['per_day'],
            86400
        );
    }

    /**
     * Check monthly invoice quota.
     *
     * @throws LicenseException
     */
    public function checkInvoiceQuota(License $license): void
    {
        $month = now()->format('Y-m');

        $monthlyCount = LicenseUsage::where('license_id', $license->id)
            ->where('usage_month', $month)
            ->sum('invoices_submitted');

        if ($monthlyCount >= $license->max_invoices_per_month) {
            throw LicenseException::quotaExceeded(
                'invoices_per_month',
                $license->max_invoices_per_month,
                (int) $monthlyCount
            );
        }
    }

    /**
     * Check rate limit for a specific time window.
     *
     * @throws LicenseException
     */
    private function checkWindowLimit(
        License $license,
        string $windowType,
        string $windowKey,
        int $limit,
        int $windowSeconds
    ): void {
        $cacheKey = "rate_limit:{$license->id}:{$windowType}:{$windowKey}";

        // Try Redis first
        try {
            $current = Cache::increment($cacheKey);

            if ($current === 1) {
                Cache::put($cacheKey, 1, $windowSeconds);
            }

            if ($current > $limit) {
                $retryAfter = $this->getRetryAfter($windowType);
                throw LicenseException::rateLimited($windowType, $limit, $retryAfter);
            }
        } catch (LicenseException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Redis unavailable, fall back to DB
            Log::debug('Rate limit cache failed, using DB fallback', [
                'error' => $e->getMessage(),
            ]);
            $this->checkWindowLimitDb($license, $windowType, $windowKey, $limit, $windowSeconds);
        }
    }

    /**
     * Database fallback for rate limiting.
     */
    private function checkWindowLimitDb(
        License $license,
        string $windowType,
        string $windowKey,
        int $limit,
        int $windowSeconds
    ): void {
        $record = DB::table('license_rate_limits')
            ->where('license_id', $license->id)
            ->where('window_type', $windowType)
            ->where('window_key', $windowKey)
            ->first();

        if ($record) {
            if ($record->request_count >= $limit) {
                $retryAfter = $this->getRetryAfter($windowType);
                throw LicenseException::rateLimited($windowType, $limit, $retryAfter);
            }

            DB::table('license_rate_limits')
                ->where('id', $record->id)
                ->increment('request_count');
        } else {
            DB::table('license_rate_limits')->insert([
                'id' => Str::uuid()->toString(),
                'license_id' => $license->id,
                'window_type' => $windowType,
                'window_key' => $windowKey,
                'request_count' => 1,
                'window_start' => now(),
                'window_expires' => now()->addSeconds($windowSeconds),
            ]);
        }
    }

    /**
     * Get retry-after seconds based on window type.
     */
    private function getRetryAfter(string $windowType): int
    {
        return match ($windowType) {
            'minute' => 60 - now()->second,
            'hour' => 3600 - (now()->minute * 60 + now()->second),
            'day' => 86400 - (now()->hour * 3600 + now()->minute * 60 + now()->second),
            default => 60,
        };
    }

    /**
     * Get usage summary for a license.
     */
    public function getUsageSummary(License $license, ?string $month = null): array
    {
        $month = $month ?? now()->format('Y-m');

        $usage = LicenseUsage::where('license_id', $license->id)
            ->where('usage_month', $month)
            ->get();

        $totals = [
            'invoices_submitted' => $usage->sum('invoices_submitted'),
            'invoices_cleared' => $usage->sum('invoices_cleared'),
            'invoices_reported' => $usage->sum('invoices_reported'),
            'invoices_failed' => $usage->sum('invoices_failed'),
            'api_calls' => $usage->sum('api_calls'),
            'api_errors' => $usage->sum('api_errors'),
            'invoice_total_value' => $usage->sum('invoice_total_value'),
        ];

        return [
            'month' => $month,
            'totals' => $totals,
            'limits' => [
                'invoices_per_month' => $license->max_invoices_per_month,
                'invoices_remaining' => max(0, $license->max_invoices_per_month - $totals['invoices_submitted']),
                'api_calls_per_day' => $license->max_api_calls_per_day,
                'api_calls_per_minute' => $license->max_api_calls_per_minute,
            ],
            'utilization' => [
                'invoices_percent' => $license->max_invoices_per_month > 0
                    ? round(($totals['invoices_submitted'] / $license->max_invoices_per_month) * 100, 2)
                    : 0,
                'success_rate' => $totals['invoices_submitted'] > 0
                    ? round((($totals['invoices_cleared'] + $totals['invoices_reported']) / $totals['invoices_submitted']) * 100, 2)
                    : 100,
            ],
            'daily_breakdown' => $usage->map(fn ($u) => [
                'date' => $u->usage_date->toDateString(),
                'invoices' => $u->invoices_submitted,
                'api_calls' => $u->api_calls,
            ])->toArray(),
        ];
    }

    /**
     * Clean up expired rate limit records.
     */
    public function cleanupExpiredRateLimits(): int
    {
        return DB::table('license_rate_limits')
            ->where('window_expires', '<', now())
            ->delete();
    }
}
