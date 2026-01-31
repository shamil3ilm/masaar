<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\Config\ZatcaConfig;
use App\Domains\Compliance\Zatca\Enums\ErrorCode;
use App\Domains\Compliance\Zatca\Exceptions\ZatcaException;
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
class KillSwitchManager
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
        return (int) config('zatca.kill_switch.cache_ttl_seconds', 3600);
    }

    /**
     * Get max duration for time-boxed switches from config.
     */
    private function getMaxDurationSeconds(): int
    {
        return (int) config('zatca.kill_switch.max_duration_seconds', 14400);
    }

    /**
     * Get alert threshold from config.
     */
    private function getAlertThresholdSeconds(): int
    {
        return (int) config('zatca.kill_switch.alert_threshold_seconds', 1800);
    }

    /**
     * Enable a kill switch with optional time-boxing.
     *
     * @param string $switch Kill switch type
     * @param string $scope Global or tenant-specific
     * @param string|null $tenantId Tenant ID for tenant-scoped switches
     * @param string|null $reason REQUIRED: Reason for enabling (audit trail)
     * @param string|null $enabledBy Who enabled the switch
     * @param int|null $durationSeconds How long to keep enabled (null = permanent, max 4 hours default)
     * @param bool $requireReason If true, throws if reason is empty
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
        $switches = $this->getAllSwitches();

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
            'alert_sent' => false,
        ];

        $this->saveSwitches($switches);

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
        $switches = $this->getAllSwitches();

        if (isset($switches[$key])) {
            $enabledAt = $switches[$key]['enabled_at'] ?? 'unknown';
            $enabledBy = $switches[$key]['enabled_by'] ?? 'unknown';
            $reason = $switches[$key]['reason'] ?? null;

            unset($switches[$key]);
            $this->saveSwitches($switches);

            Log::info('Kill switch DISABLED', [
                'switch' => $switch,
                'scope' => $scope,
                'tenant_id' => $tenantId,
                'was_enabled_at' => $enabledAt,
                'was_enabled_by' => $enabledBy,
                'was_reason' => $reason,
                'disabled_by' => $disabledBy,
            ]);
        }
    }

    /**
     * Check if a kill switch is enabled.
     * Automatically handles expiry and alerting.
     */
    public function isEnabled(
        string $switch,
        ?string $tenantId = null
    ): bool {
        $switches = $this->getAllSwitches();
        $modified = false;

        // Check global switch first
        $globalKey = $this->buildKey($switch, self::SCOPE_GLOBAL);
        if (isset($switches[$globalKey]) && $switches[$globalKey]['enabled']) {
            $result = $this->checkSwitchExpiry($switches, $globalKey);
            if ($result['expired']) {
                $modified = true;
            } elseif ($result['enabled']) {
                $this->checkAndSendAlert($switches, $globalKey);
                if ($modified) {
                    $this->saveSwitches($switches);
                }
                return true;
            }
        }

        // Check tenant-specific switch
        if ($tenantId !== null) {
            $tenantKey = $this->buildKey($switch, self::SCOPE_TENANT, $tenantId);
            if (isset($switches[$tenantKey]) && $switches[$tenantKey]['enabled']) {
                $result = $this->checkSwitchExpiry($switches, $tenantKey);
                if ($result['expired']) {
                    $modified = true;
                } elseif ($result['enabled']) {
                    $this->checkAndSendAlert($switches, $tenantKey);
                    if ($modified) {
                        $this->saveSwitches($switches);
                    }
                    return true;
                }
            }
        }

        if ($modified) {
            $this->saveSwitches($switches);
        }

        return false;
    }

    /**
     * Check if a switch has expired and auto-disable if so.
     *
     * @return array{expired: bool, enabled: bool}
     */
    private function checkSwitchExpiry(array &$switches, string $key): array
    {
        if (!isset($switches[$key])) {
            return ['expired' => false, 'enabled' => false];
        }

        $switchData = $switches[$key];

        // No expiry set - permanently enabled
        if (empty($switchData['expires_at'])) {
            return ['expired' => false, 'enabled' => true];
        }

        $expiresAt = new \DateTimeImmutable($switchData['expires_at']);
        if ($expiresAt <= now()) {
            // Auto-disable expired switch
            Log::info('Kill switch auto-expired', [
                'switch' => $switchData['switch'],
                'scope' => $switchData['scope'],
                'tenant_id' => $switchData['tenant_id'] ?? null,
                'was_enabled_at' => $switchData['enabled_at'],
                'expired_at' => $switchData['expires_at'],
                'duration' => $switchData['duration_seconds'] ?? 'unknown',
            ]);

            unset($switches[$key]);
            return ['expired' => true, 'enabled' => false];
        }

        return ['expired' => false, 'enabled' => true];
    }

    /**
     * Check and send alert if switch has been enabled too long.
     */
    private function checkAndSendAlert(array &$switches, string $key): void
    {
        if (!isset($switches[$key])) {
            return;
        }

        $switchData = &$switches[$key];

        // Already sent alert
        if (!empty($switchData['alert_sent'])) {
            return;
        }

        $enabledAt = new \DateTimeImmutable($switchData['enabled_at']);
        $enabledDuration = now()->getTimestamp() - $enabledAt->getTimestamp();

        if ($enabledDuration >= $this->getAlertThresholdSeconds()) {
            // Mark alert as sent
            $switchData['alert_sent'] = true;
            $switches[$key] = $switchData;

            Log::warning('ALERT: Kill switch enabled for extended period', [
                'switch' => $switchData['switch'],
                'scope' => $switchData['scope'],
                'tenant_id' => $switchData['tenant_id'] ?? null,
                'enabled_at' => $switchData['enabled_at'],
                'enabled_by' => $switchData['enabled_by'],
                'reason' => $switchData['reason'],
                'duration_seconds' => $enabledDuration,
                'threshold_seconds' => $this->getAlertThresholdSeconds(),
                'expires_at' => $switchData['expires_at'] ?? 'never',
            ]);

            // Here you would typically dispatch to your alerting system
            // e.g., Slack, PagerDuty, email, etc.
            $this->dispatchAlert($switchData, $enabledDuration);
        }
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
     * @throws ZatcaException
     */
    public function assertNotEnabled(
        string $switch,
        ?string $tenantId = null
    ): void {
        if ($this->isEnabled($switch, $tenantId)) {
            $info = $this->getSwitchInfo($switch, $tenantId);

            throw new ZatcaException(
                "Operation blocked: {$switch} kill switch is enabled" .
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
            if (!$switch['enabled']) {
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
        $allSwitches = [
            self::SWITCH_ISSUANCE,
            self::SWITCH_SUBMISSION,
            self::SWITCH_CLEARANCE,
            self::SWITCH_REPORTING,
            self::SWITCH_SIGNING,
            self::SWITCH_OFFLINE_MODE,
        ];

        $status = [];

        foreach ($allSwitches as $switch) {
            $info = $this->getSwitchInfo($switch, $tenantId);
            $enabled = $this->isEnabled($switch, $tenantId);
            $status[$switch] = [
                'enabled' => $enabled,
                'info' => $info,
                'expires_at' => $info['expires_at'] ?? null,
                'is_time_boxed' => !empty($info['expires_at']),
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
     * Extend the duration of an existing time-boxed switch.
     */
    public function extendDuration(
        string $switch,
        int $additionalSeconds,
        string $scope = self::SCOPE_GLOBAL,
        ?string $tenantId = null,
        ?string $extendedBy = null,
        ?string $reason = null
    ): void {
        $key = $this->buildKey($switch, $scope, $tenantId);
        $switches = $this->getAllSwitches();

        if (!isset($switches[$key])) {
            throw new \InvalidArgumentException("Kill switch {$switch} is not enabled");
        }

        $switchData = $switches[$key];

        // Calculate new expiry
        $currentExpiry = $switchData['expires_at']
            ? new \DateTimeImmutable($switchData['expires_at'])
            : now();

        $newExpiry = $currentExpiry->modify("+{$additionalSeconds} seconds");

        // Enforce maximum total duration from original enable time
        $enabledAt = new \DateTimeImmutable($switchData['enabled_at']);
        $totalDuration = $newExpiry->getTimestamp() - $enabledAt->getTimestamp();

        if ($totalDuration > $this->getMaxDurationSeconds() * 2) {
            throw new \InvalidArgumentException(
                'Cannot extend kill switch beyond maximum total duration (8 hours from original enable time)'
            );
        }

        $switches[$key]['expires_at'] = $newExpiry->format(\DateTimeInterface::ATOM);
        $switches[$key]['extension_history'][] = [
            'extended_at' => now()->toIso8601String(),
            'extended_by' => $extendedBy,
            'additional_seconds' => $additionalSeconds,
            'reason' => $reason,
        ];

        $this->saveSwitches($switches);

        Log::warning('Kill switch duration EXTENDED', [
            'switch' => $switch,
            'scope' => $scope,
            'tenant_id' => $tenantId,
            'additional_seconds' => $additionalSeconds,
            'new_expires_at' => $newExpiry->format(\DateTimeInterface::ATOM),
            'extended_by' => $extendedBy,
            'reason' => $reason,
        ]);
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
            return "{$minutes} minute" . ($minutes !== 1 ? 's' : '');
        }

        $hours = (int) ($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes > 0) {
            return "{$hours} hour" . ($hours !== 1 ? 's' : '') . " {$remainingMinutes} min";
        }

        return "{$hours} hour" . ($hours !== 1 ? 's' : '');
    }

    /**
     * Emergency: Enable all kill switches.
     */
    public function emergencyStop(?string $reason = null, ?string $enabledBy = null): void
    {
        $allSwitches = [
            self::SWITCH_ISSUANCE,
            self::SWITCH_SUBMISSION,
            self::SWITCH_CLEARANCE,
            self::SWITCH_REPORTING,
            self::SWITCH_SIGNING,
        ];

        foreach ($allSwitches as $switch) {
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
        $switches = $this->getAllSwitches();

        foreach (array_keys($switches) as $key) {
            unset($switches[$key]);
        }

        $this->saveSwitches($switches);

        Log::info('All kill switches DISABLED - operations resumed', [
            'disabled_by' => $disabledBy,
        ]);
    }

    /**
     * Get all switches from cache.
     */
    private function getAllSwitches(): array
    {
        return Cache::get(self::CACHE_KEY, []);
    }

    /**
     * Save switches to cache.
     */
    private function saveSwitches(array $switches): void
    {
        Cache::put(self::CACHE_KEY, $switches, now()->addSeconds($this->getCacheTtl()));
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
        $validSwitches = [
            self::SWITCH_ISSUANCE,
            self::SWITCH_SUBMISSION,
            self::SWITCH_CLEARANCE,
            self::SWITCH_REPORTING,
            self::SWITCH_SIGNING,
            self::SWITCH_OFFLINE_MODE,
        ];

        if (!in_array($switch, $validSwitches, true)) {
            throw new \InvalidArgumentException(
                "Invalid kill switch: {$switch}. Valid switches: " . implode(', ', $validSwitches)
            );
        }
    }
}
