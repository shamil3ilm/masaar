<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Kill Switch Manager for ZATCA Operations.
 *
 * Provides emergency controls to:
 * - Stop all issuance
 * - Stop all submissions
 * - Force offline mode
 * - Tenant-specific controls
 *
 * This addresses:
 * - Month-end close + ZATCA outage
 * - Go-live on tax change date
 * - Emergency shutdowns
 * - Per-tenant blast radius containment
 */
class KillSwitch
{
    /**
     * Kill switch types.
     */
    public const SWITCH_ISSUANCE = 'issuance';

    public const SWITCH_SUBMISSION = 'submission';

    public const SWITCH_CLEARANCE = 'clearance';

    public const SWITCH_REPORTING = 'reporting';

    public const SWITCH_SIGNING = 'signing';

    public const SWITCH_OFFLINE_MODE = 'offline_mode';

    /**
     * Scope types.
     */
    /**
     * Every switch there is, in the order an operator reads them.
     *
     * validateSwitch and getStatus each wrote this list out, so adding a
     * switch meant remembering both - and the console needs the same list
     * again to render one control per switch.
     *
     * @var list<string>
     */
    public const SWITCHES = [
        self::SWITCH_ISSUANCE,
        self::SWITCH_SUBMISSION,
        self::SWITCH_CLEARANCE,
        self::SWITCH_REPORTING,
        self::SWITCH_SIGNING,
        self::SWITCH_OFFLINE_MODE,
    ];

    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_TENANT = 'tenant';

    /**
     * Cache key for kill switches.
     */
    private const CACHE_KEY = 'zatca:kill_switches';

    /**
     * Get cache TTL from config.
     */
    private function getCacheTtl(): int
    {
        return (int) config('fatoora.kill_switch.cache_ttl_seconds', 3600);
    }

    /**
     * Get max duration for time-boxed switches from config.
     */
    private function getMaxDurationSeconds(): int
    {
        return (int) config('fatoora.kill_switch.max_duration_seconds', 14400);
    }

    /**
     * Get alert threshold from config.
     */
    private function getAlertThresholdSeconds(): int
    {
        return (int) config('fatoora.kill_switch.alert_threshold_seconds', 1800);
    }

    /**
     * Enable a kill switch with optional time-boxing.
     *
     * @param  string  $switch  Kill switch type
     * @param  string  $scope  Global or tenant-specific
     * @param  string|null  $tenantId  Tenant ID for tenant-scoped switches
     * @param  string|null  $reason  REQUIRED: Reason for enabling (audit trail)
     * @param  string|null  $enabledBy  Who enabled the switch
     * @param  int|null  $durationSeconds  How long to keep enabled (null = permanent, max 4 hours default)
     * @param  bool  $requireReason  If true, throws if reason is empty
     */
    public function enable(
        string $switch,
        string $scope = self::SCOPE_GLOBAL,
        ?string $tenantId = null,
        ?string $reason = null,
        ?string $enabledBy = null,
        ?int $durationSeconds = null,
        bool $requireReason = true
    ): void {
        $this->validateSwitch($switch);

        // Enforce mandatory reason logging
        if ($requireReason && empty($reason)) {
            throw new \InvalidArgumentException(
                'Kill switch reason is required for audit compliance'
            );
        }

        // Enforce time-boxing with maximum duration
        $expiresAt = null;
        if ($durationSeconds !== null) {
            if ($durationSeconds > $this->getMaxDurationSeconds()) {
                Log::warning('Kill switch duration exceeds maximum, capping', [
                    'requested' => $durationSeconds,
                    'max' => $this->getMaxDurationSeconds(),
                ]);
                $durationSeconds = $this->getMaxDurationSeconds();
            }
            $expiresAt = now()->addSeconds($durationSeconds)->toIso8601String();
        }

        $key = $this->buildKey($switch, $scope, $tenantId);

        $this->mutate(function (array $switches) use (
            $key, $switch, $scope, $tenantId, $reason, $enabledBy, $durationSeconds, $expiresAt
        ): array {
            $switches[$key] = [
                'switch' => $switch,
                'scope' => $scope,
                'tenant_id' => $tenantId,
                'enabled' => true,
                'enabled_at' => now()->toIso8601String(),
                'enabled_by' => $enabledBy ?? 'system',
                'reason' => $reason,
                'duration_seconds' => $durationSeconds,
                'expires_at' => $expiresAt,
            ];

            return $switches;
        });

        Log::critical('Kill switch ENABLED', [
            'switch' => $switch,
            'scope' => $scope,
            'tenant_id' => $tenantId,
            'reason' => $reason,
            'enabled_by' => $enabledBy,
            'expires_at' => $expiresAt,
            'is_time_boxed' => $expiresAt !== null,
        ]);
    }

    /**
     * Disable a kill switch.
     */
    public function disable(
        string $switch,
        string $scope = self::SCOPE_GLOBAL,
        ?string $tenantId = null,
        ?string $disabledBy = null
    ): void {
        $key = $this->buildKey($switch, $scope, $tenantId);
        $removed = null;

        $this->mutate(function (array $switches) use ($key, &$removed): array {
            $removed = $switches[$key] ?? null;
            unset($switches[$key]);

            return $switches;
        });

        // Logged outside the lock: what was there is read inside it, so the
        // record is of the entry this call actually removed rather than
        // whatever a concurrent operator left behind.
        if ($removed !== null) {
            Log::info('Kill switch DISABLED', [
                'switch' => $switch,
                'scope' => $scope,
                'tenant_id' => $tenantId,
                'was_enabled_at' => $removed['enabled_at'] ?? 'unknown',
                'was_enabled_by' => $removed['enabled_by'] ?? 'unknown',
                'was_reason' => $removed['reason'] ?? null,
                'disabled_by' => $disabledBy,
            ]);
        }
    }

    /**
     * Whether a switch is on, for the whole platform or for one tenant.
     *
     * This asks a question and writes nothing, which it did not used to do.
     * It read the registry and, whenever it noticed an expired switch, wrote
     * the whole array back - so a switch an operator enabled between that
     * read and that write was erased by a request that was only asking
     * whether it could proceed. Submitter and SubmissionGuard call this on
     * every issuance and every submission, so on a busy tenant the window was
     * hit constantly, and the operator was left reading a 'Kill switch
     * ENABLED' log line for a switch that was no longer on.
     *
     * Expiry is dropped on read now, in getAllSwitches, so an entry that is
     * present is an entry that is live.
     */
    public function isEnabled(
        string $switch,
        ?string $tenantId = null
    ): bool {
        $switches = $this->getAllSwitches();

        foreach ($this->keysFor($switch, $tenantId) as $key) {
            if (empty($switches[$key]['enabled'])) {
                continue;
            }

            $this->alertIfLongRunning($switches[$key], $key);

            return true;
        }

        return false;
    }

    /**
     * The registry keys that can answer for this switch, most general first.
     *
     * @return list<string>
     */
    private function keysFor(string $switch, ?string $tenantId): array
    {
        $keys = [$this->buildKey($switch, self::SCOPE_GLOBAL)];

        if ($tenantId !== null) {
            $keys[] = $this->buildKey($switch, self::SCOPE_TENANT, $tenantId);
        }

        return $keys;
    }

    /**
     * Warn once when a switch has been on for longer than the threshold.
     *
     * The flag recording that the warning had been sent used to live on the
     * switch itself, which made sending it a write to the registry from a
     * read path. Worse, that write was guarded by a $modified flag this
     * method never set, so the flag was never persisted at all and the alert
     * re-fired on every single check.
     *
     * Cache::add is the whole mechanism now. It succeeds only when the key is
     * absent, so exactly one caller sends the alert, and the registry is not
     * touched. CircuitBreaker uses the same primitive for the same reason.
     *
     * @param  array<string, mixed>  $switchData
     */
    private function alertIfLongRunning(array $switchData, string $key): void
    {
        $enabledAt = new \DateTimeImmutable($switchData['enabled_at']);
        $enabledDuration = now()->getTimestamp() - $enabledAt->getTimestamp();

        if ($enabledDuration < $this->getAlertThresholdSeconds()) {
            return;
        }

        if (! Cache::add(self::CACHE_KEY.':alerted:'.$key, true, $this->getCacheTtl())) {
            return;
        }

        Log::warning('ALERT: Kill switch enabled for extended period', [
            'switch' => $switchData['switch'],
            'scope' => $switchData['scope'],
            'tenant_id' => $switchData['tenant_id'] ?? null,
            'enabled_at' => $switchData['enabled_at'],
            'enabled_by' => $switchData['enabled_by'],
            'reason' => $switchData['reason'] ?? null,
            'duration_seconds' => $enabledDuration,
            'threshold_seconds' => $this->getAlertThresholdSeconds(),
            'expires_at' => $switchData['expires_at'] ?? 'never',
        ]);

        $this->dispatchAlert($switchData, $enabledDuration);
    }

    /**
     * Dispatch alert to external systems.
     * Override this method to integrate with your alerting system.
     */
    protected function dispatchAlert(array $switchData, int $enabledDuration): void
    {
        // Default implementation just logs
        // In production, integrate with Slack, PagerDuty, etc.
        Log::channel('slack')->warning('Kill switch alert', [
            'message' => sprintf(
                '⚠️ Kill switch "%s" has been enabled for %d minutes by %s. Reason: %s',
                $switchData['switch'],
                (int) ($enabledDuration / 60),
                $switchData['enabled_by'],
                $switchData['reason'] ?? 'No reason provided'
            ),
        ]);
    }

    /**
     * Assert that a switch is not enabled (throw if it is).
     *
     * @throws FatooraException
     */
    public function assertNotEnabled(
        string $switch,
        ?string $tenantId = null
    ): void {
        if ($this->isEnabled($switch, $tenantId)) {
            $info = $this->getSwitchInfo($switch, $tenantId);

            throw new FatooraException(
                "Operation blocked: {$switch} kill switch is enabled".
                    ($info['reason'] ? " - Reason: {$info['reason']}" : ''),
                ErrorCode::SYS_MAINTENANCE_MODE,
                [
                    'kill_switch' => $switch,
                    'enabled_at' => $info['enabled_at'] ?? null,
                    'reason' => $info['reason'] ?? null,
                ]
            );
        }
    }

    /**
     * Get information about a specific switch.
     */
    public function getSwitchInfo(
        string $switch,
        ?string $tenantId = null
    ): ?array {
        $switches = $this->getAllSwitches();

        // Check global first
        $globalKey = $this->buildKey($switch, self::SCOPE_GLOBAL);
        if (isset($switches[$globalKey])) {
            return $switches[$globalKey];
        }

        // Check tenant-specific
        if ($tenantId !== null) {
            $tenantKey = $this->buildKey($switch, self::SCOPE_TENANT, $tenantId);
            if (isset($switches[$tenantKey])) {
                return $switches[$tenantKey];
            }
        }

        return null;
    }

    /**
     * Check if offline mode is forced.
     */
    public function isOfflineModeForced(?string $tenantId = null): bool
    {
        return $this->isEnabled(self::SWITCH_OFFLINE_MODE, $tenantId);
    }

    /**
     * Check if issuance is blocked.
     */
    public function isIssuanceBlocked(?string $tenantId = null): bool
    {
        return $this->isEnabled(self::SWITCH_ISSUANCE, $tenantId);
    }

    /**
     * Check if submission is blocked.
     */
    public function isSubmissionBlocked(?string $tenantId = null): bool
    {
        return $this->isEnabled(self::SWITCH_SUBMISSION, $tenantId)
            || $this->isEnabled(self::SWITCH_OFFLINE_MODE, $tenantId);
    }

    /**
     * Check if clearance is blocked.
     */
    public function isClearanceBlocked(?string $tenantId = null): bool
    {
        return $this->isEnabled(self::SWITCH_CLEARANCE, $tenantId)
            || $this->isSubmissionBlocked($tenantId);
    }

    /**
     * Check if reporting is blocked.
     */
    public function isReportingBlocked(?string $tenantId = null): bool
    {
        return $this->isEnabled(self::SWITCH_REPORTING, $tenantId)
            || $this->isSubmissionBlocked($tenantId);
    }

    /**
     * Get all enabled switches.
     */
    public function getEnabledSwitches(?string $tenantId = null): array
    {
        $switches = $this->getAllSwitches();
        $enabled = [];

        foreach ($switches as $key => $switch) {
            if (! $switch['enabled']) {
                continue;
            }

            // Include global switches
            if ($switch['scope'] === self::SCOPE_GLOBAL) {
                $enabled[] = $switch;

                continue;
            }

            // Include tenant-specific switches
            if ($tenantId !== null && $switch['tenant_id'] === $tenantId) {
                $enabled[] = $switch;
            }
        }

        return $enabled;
    }

    /**
     * Get full status report.
     */
    public function getStatus(?string $tenantId = null): array
    {
        $status = [];

        foreach (self::SWITCHES as $switch) {
            $info = $this->getSwitchInfo($switch, $tenantId);
            $enabled = $this->isEnabled($switch, $tenantId);
            $status[$switch] = [
                'enabled' => $enabled,
                'info' => $info,
                'expires_at' => $info['expires_at'] ?? null,
                'is_time_boxed' => ! empty($info['expires_at']),
            ];
        }

        return [
            'tenant_id' => $tenantId,
            'switches' => $status,
            'is_offline_mode' => $this->isOfflineModeForced($tenantId),
            'is_fully_operational' => empty($this->getEnabledSwitches($tenantId)),
            'long_running_switches' => $this->getLongRunningSwitches($tenantId),
        ];
    }

    /**
     * Get switches that have been enabled for longer than the alert threshold.
     */
    public function getLongRunningSwitches(?string $tenantId = null): array
    {
        $enabled = $this->getEnabledSwitches($tenantId);
        $longRunning = [];

        foreach ($enabled as $switch) {
            $enabledAt = new \DateTimeImmutable($switch['enabled_at']);
            $duration = now()->getTimestamp() - $enabledAt->getTimestamp();

            if ($duration >= $this->getAlertThresholdSeconds()) {
                $longRunning[] = [
                    'switch' => $switch['switch'],
                    'scope' => $switch['scope'],
                    'tenant_id' => $switch['tenant_id'] ?? null,
                    'enabled_at' => $switch['enabled_at'],
                    'enabled_by' => $switch['enabled_by'],
                    'reason' => $switch['reason'],
                    'duration_seconds' => $duration,
                    'duration_human' => $this->humanizeDuration($duration),
                    'expires_at' => $switch['expires_at'] ?? null,
                ];
            }
        }

        return $longRunning;
    }

    /**
     * Convert seconds to human readable duration.
     */
    private function humanizeDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }

        $minutes = (int) ($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes} minute".($minutes !== 1 ? 's' : '');
        }

        $hours = (int) ($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes > 0) {
            return "{$hours} hour".($hours !== 1 ? 's' : '')." {$remainingMinutes} min";
        }

        return "{$hours} hour".($hours !== 1 ? 's' : '');
    }

    /**
     * Emergency: Enable all kill switches.
     */
    public function emergencyStop(?string $reason = null, ?string $enabledBy = null): void
    {
        // Everything except forcing offline mode, which is not a stop.
        //
        // The other five refuse the step. offline_mode makes submission queue
        // instead of attempting the authority, so enabling it here would start
        // accepting work during an emergency stop rather than stopping it.
        //
        // Derived from SWITCHES rather than listed again: this was a third
        // copy of that list, and nothing said the one missing entry was
        // deliberate.
        $stoppable = array_diff(self::SWITCHES, [self::SWITCH_OFFLINE_MODE]);

        foreach ($stoppable as $switch) {
            $this->enable(
                $switch,
                self::SCOPE_GLOBAL,
                null,
                $reason ?? 'Emergency stop activated',
                $enabledBy
            );
        }

        Log::critical('EMERGENCY STOP activated', [
            'reason' => $reason,
            'enabled_by' => $enabledBy,
        ]);
    }

    /**
     * Resume all operations (disable all kill switches).
     */
    public function resumeAll(?string $disabledBy = null): void
    {
        $resumed = 0;

        $this->mutate(function (array $switches) use (&$resumed): array {
            $resumed = count($switches);

            return [];
        });

        Log::info('All kill switches DISABLED - operations resumed', [
            'disabled_by' => $disabledBy,
            'switches_cleared' => $resumed,
        ]);
    }

    /**
     * Get all switches from cache.
     */
    /**
     * Apply a change to the registry under a lock.
     *
     * The registry is one cache entry holding every switch, so a mutation is
     * read-all, change-one, write-all. Two operators acting at once both read
     * the same array and the second write dropped the first one's switch -
     * during exactly the kind of incident when two people are both reaching
     * for the controls. Each of them saw a 'Kill switch ENABLED' line for it.
     *
     * The lock is held for the read and the write together, which is the only
     * way the sequence is safe. Ten seconds is the lease, five the wait: long
     * enough for a cache round trip, short enough that an operator gets an
     * error rather than a hang if the lock is stuck.
     *
     * @param  callable(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $change
     */
    private function mutate(callable $change): void
    {
        Cache::lock(self::CACHE_KEY.':lock', 10)->block(5, function () use ($change): void {
            $this->saveSwitches($change($this->getAllSwitches()));
        });
    }

    /**
     * The switches that are on, with expired ones left out.
     *
     * Left out rather than deleted, because answering whether a switch is on
     * must not modify the registry - the read-modify-write that used to do
     * that housekeeping is what erased switches enabled in between. An
     * expired entry simply is not on; it leaves the cache the next time the
     * registry is written, or when its own TTL passes.
     *
     * The 'Kill switch auto-expired' line that the old housekeeping logged is
     * gone with it. It was emitted from a read path, so keeping it here would
     * mean logging it on every request for as long as the entry lingered.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getAllSwitches(): array
    {
        $switches = Cache::get(self::CACHE_KEY, []);

        return array_filter($switches, static function (array $switch): bool {
            if (empty($switch['expires_at'])) {
                return true;
            }

            return new \DateTimeImmutable($switch['expires_at']) > now();
        });
    }

    /**
     * Save switches to cache.
     */
    /**
     * Write the registry, with no expiry of its own.
     *
     * This used to put it with a one-hour TTL, so the cache entry's lifetime
     * overrode every switch's. An indefinite switch - the branch that leaves
     * expires_at null, which the console shows as "turned off by hand" -
     * released an hour after the last write and logged nothing. And
     * max_duration_seconds is four hours, four times that TTL, so even a
     * deliberately time-boxed four-hour stop released after one. The cap on
     * how long a switch may be set for was unenforceable in the direction
     * that mattered.
     *
     * There is nothing to reclaim by expiring this: it holds one entry per
     * switch per scope, a dozen at most, and each entry carries the expiry
     * that getAllSwitches enforces. resumeAll empties it.
     *
     * getCacheTtl is still used, for how long the long-running alert
     * remembers it has already fired.
     */
    private function saveSwitches(array $switches): void
    {
        Cache::forever(self::CACHE_KEY, $switches);
    }

    /**
     * Build cache key for a switch.
     */
    private function buildKey(string $switch, string $scope, ?string $tenantId = null): string
    {
        if ($scope === self::SCOPE_TENANT && $tenantId !== null) {
            return "{$switch}:{$scope}:{$tenantId}";
        }

        return "{$switch}:{$scope}";
    }

    /**
     * Validate switch type.
     *
     * @throws \InvalidArgumentException
     */
    private function validateSwitch(string $switch): void
    {
        if (! in_array($switch, self::SWITCHES, true)) {
            throw new \InvalidArgumentException(
                "Invalid kill switch: {$switch}. Valid switches: ".implode(', ', self::SWITCHES)
            );
        }
    }
}
