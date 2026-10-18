<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseUsage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Usage Metering Service.
 *
 * Tracks API usage, enforces rate limits, and manages quotas.
 * Uses Redis for real-time rate limiting with DB fallback.
 */
class UsageMeteringService
{
    public function __construct(
        private readonly AllowanceWarnings $warnings,
    ) {}

    /**
     * Record the outcome of a submission against the tenant's licence.
     *
     * recordInvoiceSubmission() below is the only writer of
     * license_usage.invoices_submitted, and checkInvoiceQuota() is the only
     * reader — it sums that column for the month and refuses once the tier's
     * invoices_per_month is reached. Nothing called the writer, so the sum was
     * always zero: the quota never refused anything, and the usage endpoint
     * reported a month of submissions as nothing at all. API calls were metered;
     * invoices were not.
     *
     * Called where the outcome is known rather than when the request arrives,
     * so the synchronous and queued paths record the same thing. A retry that
     * fails never reaches here; one that succeeds is counted once.
     *
     * @param  string  $state  The submission state ZATCA's response produced.
     */
    public function recordSubmissionOutcome(string $organizationId, string $state, float $invoiceValue = 0.0): void
    {
        $license = License::withoutGlobalScopes()
            ->where('org_id', $organizationId)
            ->first();

        // An organization submitting through the staff console has no licence
        // to meter — that credential is a person, not an integrator. Nothing to
        // record rather than nothing recorded.
        if ($license === null) {
            return;
        }

        // No verdict means nothing was filed, so the slot
        // reserveInvoiceQuota took goes back. A rejection keeps its slot: the
        // authority processed the document and answered.
        if ($state === 'failed') {
            $this->releaseInvoiceQuota($license);
        }

        $this->recordInvoiceSubmission(
            license: $license,
            cleared: $state === 'cleared',
            reported: $state === 'reported',
            failed: in_array($state, ['rejected', 'failed'], true),
            invoiceValue: $invoiceValue,
        );
    }

    /**
     * Record an API call.
     */
    public function recordApiCall(License $license, bool $isError = false): void
    {
        $today = now()->startOfDay();
        $month = now()->format('Y-m');

        $usage = $this->dailyUsage($license, $today, $month);

        $usage->increment('api_calls');

        if ($isError) {
            $usage->increment('api_errors');
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
        $today = now()->startOfDay();
        $month = now()->format('Y-m');

        $usage = $this->dailyUsage($license, $today, $month);

        // Not invoices_submitted. reserveInvoiceQuota takes that slot at the
        // gate, before the authority is called, because a limit cannot be
        // enforced afterwards. Counting it again here would spend the
        // taxpayer's allowance twice for one invoice.
        if ($cleared) {
            $usage->increment('invoices_cleared');
        }
        if ($reported) {
            $usage->increment('invoices_reported');
        }
        if ($failed) {
            $usage->increment('invoices_failed');
        }
        if ($invoiceValue > 0) {
            $usage->increment('total_value', $invoiceValue);
        }
    }

    /**
     * The row today's counters belong to.
     *
     * The date is a Carbon rather than a "Y-m-d" string, and the increments go
     * through the model rather than a second query on usage_date. Both matter
     * for the same reason: Eloquent writes usage_date through its date cast as
     * "2026-08-20 00:00:00", and a plain "2026-08-20" does not match that on
     * SQLite. MySQL hides it — a DATE column truncates on insert, so the two
     * agree there — which is why the counters worked in production and the
     * second write of a day collided on the unique key under test.
     *
     * Passing a Carbon makes both the lookup and the insert go through the same
     * conversion, so the two engines behave alike.
     */
    private function dailyUsage(License $license, CarbonInterface $date, string $month): LicenseUsage
    {
        return LicenseUsage::firstOrCreate(
            ['license_id' => $license->id, 'usage_date' => $date],
            ['usage_month' => $month],
        );
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
                'per_minute' => $license->calls_per_min,
                'per_day' => $license->calls_per_day,
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

        // The day's calls against the customer's allowance, when it has one.
        if (! $license->isUnlimited('calls_per_day')) {
            $this->warnings->check($license, 'calls_per_day', (int) DB::table('license_rate_limits')
                ->where(['license_id' => $license->id, 'window_type' => 'day', 'window_key' => now()->format('Y-m-d')])
                ->value('request_count'));
        }
    }

    /**
     * Check monthly invoice quota.
     *
     * @throws LicenseException
     */
    /**
     * Take a slot against the month's allowance, or refuse.
     *
     * checkInvoiceQuota below only reads, and is what the license.quota
     * middleware calls to refuse early with a clear answer before any work is
     * done. This is the one that decides, and it is called from inside
     * SubmissionTracker::createSubmission's transaction - the one place every
     * submission path passes through, which is why enforcement moved here
     * from a middleware that only two partner routes carry.
     *
     * The licence row is locked for the duration. The limit is a sum across
     * the month's daily rows, so there is no single-row conditional update
     * that can express it; locking serialises quota decisions for one
     * taxpayer and nobody else, for the two queries this takes.
     *
     * The slot is taken now rather than when the authority answers, because a
     * limit enforced after the document is filed is not a limit.
     * releaseInvoiceQuota gives it back when no answer came.
     */
    public function reserveInvoiceQuota(License $license): void
    {
        DB::transaction(function () use ($license): void {
            License::query()->whereKey($license->getKey())->lockForUpdate()->firstOrFail();

            $month = now()->format('Y-m');

            $monthlyCount = LicenseUsage::where('license_id', $license->id)
                ->where('usage_month', $month)
                ->sum('invoices_submitted');

            if (! $license->isUnlimited('invoices_per_month') && $monthlyCount >= $license->invoices_per_month) {
                throw LicenseException::quotaExceeded(
                    'invoices_per_month',
                    $license->invoices_per_month,
                    (int) $monthlyCount
                );
            }

            $this->dailyUsage($license, now()->startOfDay(), $month)->increment('invoices_submitted');

            // Once the slot is the customer's for certain: warned of an
            // allowance it is near or at, never refused for an unlimited one.
            DB::afterCommit(fn () => $this->warnings->check($license, 'invoices_per_month', (int) $monthlyCount + 1));
        });
    }

    /**
     * Reserve against whichever licence meters this organization.
     *
     * The same lookup recordSubmissionOutcome makes, and the same answer when
     * there is none: an organization submitting through the staff console has
     * no licence to meter, because that credential is a person rather than an
     * integrator. Nothing to reserve rather than a refusal.
     */
    public function reserveInvoiceQuotaFor(string $organizationId): void
    {
        $license = License::withoutGlobalScopes()
            ->where('org_id', $organizationId)
            ->first();

        if ($license === null) {
            return;
        }

        $this->reserveInvoiceQuota($license);
    }

    /**
     * Give back a slot for a submission that reached no verdict.
     *
     * Only for 'failed' - no answer from the authority, so nothing was filed
     * and the allowance should not have been spent. A rejection is not
     * released: ZATCA processed the document and answered, which is an
     * attempt against the allowance however unwelcome the answer.
     *
     * Never below zero. A release with no matching reservation means the gate
     * was never passed, and there is nothing to return.
     */
    public function releaseInvoiceQuota(License $license): void
    {
        LicenseUsage::where('license_id', $license->id)
            ->where('usage_date', now()->startOfDay())
            ->where('invoices_submitted', '>', 0)
            ->decrement('invoices_submitted');
    }

    public function checkInvoiceQuota(License $license): void
    {
        $month = now()->format('Y-m');

        $monthlyCount = LicenseUsage::where('license_id', $license->id)
            ->where('usage_month', $month)
            ->sum('invoices_submitted');

        if (! $license->isUnlimited('invoices_per_month') && $monthlyCount >= $license->invoices_per_month) {
            throw LicenseException::quotaExceeded(
                'invoices_per_month',
                $license->invoices_per_month,
                (int) $monthlyCount
            );
        }
    }

    /**
     * Check rate limit for a specific time window.
     *
     * Two statements, each atomic by itself. The first creates the window's
     * row unless it exists; the unique key on (license_id, window_type,
     * window_key) lets concurrent first requests race it safely. The second
     * takes a slot only while one is left, with the limit inside the UPDATE,
     * so two requests cannot both take the last slot as they could after
     * reading the count and then writing it.
     *
     * This was the fallback. In front of it sat a cache fast path that could
     * not work on the store this application configures: config/cache.php
     * defaults to `database` and DatabaseStore::incrementOrDecrement returns
     * false when the key's row does not exist, so `$current === 1` never
     * created the row and `false > $limit` never threw. The limit admitted
     * every call, and because nothing threw, the catch that was meant to
     * reach this method never ran. On Redis it failed the other way: INCR
     * created the key, then the Cache::put that gave it a TTL reset the
     * counter to 1 and a burst counted as one request.
     *
     * So there is no fast path now. One implementation, the same answer on
     * every store, and one round trip either way - the database cache driver
     * would itself have taken a transaction and a lockForUpdate to do this.
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
        $window = [
            'license_id' => $license->id,
            'window_type' => $windowType,
            'window_key' => $windowKey,
        ];

        DB::table('license_rate_limits')->insertOrIgnore([
            'id' => Str::uuid()->toString(),
            ...$window,
            'request_count' => 0,
            'window_start' => now(),
            'window_expires' => now()->addSeconds($windowSeconds),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admitted = DB::table('license_rate_limits')
            ->where($window)
            ->where('request_count', '<', $limit)
            ->increment('request_count', 1, ['updated_at' => now()]);

        if ($admitted === 0) {
            throw LicenseException::rateLimited($windowType, $limit, $this->getRetryAfter($windowType));
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
            'total_value' => $usage->sum('total_value'),
        ];

        return [
            'month' => $month,
            'totals' => $totals,
            'limits' => [
                // -1 for unlimited, as the quota endpoint reports it.
                'invoices_per_month' => $license->isUnlimited('invoices_per_month') ? -1 : $license->invoices_per_month,
                'invoices_remaining' => $license->isUnlimited('invoices_per_month')
                    ? -1
                    : max(0, $license->invoices_per_month - $totals['invoices_submitted']),
                'api_calls_per_day' => $license->isUnlimited('calls_per_day') ? -1 : $license->calls_per_day,
                'api_calls_per_minute' => $license->calls_per_min,
            ],
            'utilization' => [
                'invoices_percent' => $license->invoices_per_month > 0 && ! $license->isUnlimited('invoices_per_month')
                    ? round(($totals['invoices_submitted'] / $license->invoices_per_month) * 100, 2)
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
