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
     * Cache TTL in seconds.
     */
    private const CACHE_TTL = 3600;

    /**
     * Enable a kill switch.
     */
    public function enable(
        string $switch,
        string $scope = self::SCOPE_GLOBAL,
        ?string $tenantId = null,
        ?string $reason = null,
        ?string $enabledBy = null
    ): void {
        $this->validateSwitch($switch);

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
        ];

        $this->saveSwitches($switches);

        Log::critical('Kill switch ENABLED', [
            'switch' => $switch,
            'scope' => $scope,
            'tenant_id' => $tenantId,
            'reason' => $reason,
            'enabled_by' => $enabledBy,
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
     */
    public function isEnabled(
        string $switch,
        ?string $tenantId = null
    ): bool {
        $switches = $this->getAllSwitches();

        // Check global switch first
        $globalKey = $this->buildKey($switch, self::SCOPE_GLOBAL);
        if (isset($switches[$globalKey]) && $switches[$globalKey]['enabled']) {
            return true;
        }

        // Check tenant-specific switch
        if ($tenantId !== null) {
            $tenantKey = $this->buildKey($switch, self::SCOPE_TENANT, $tenantId);
            if (isset($switches[$tenantKey]) && $switches[$tenantKey]['enabled']) {
                return true;
            }
        }

        return false;
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
            $status[$switch] = [
                'enabled' => $this->isEnabled($switch, $tenantId),
                'info' => $info,
            ];
        }

        return [
            'tenant_id' => $tenantId,
            'switches' => $status,
            'is_offline_mode' => $this->isOfflineModeForced($tenantId),
            'is_fully_operational' => empty($this->getEnabledSwitches($tenantId)),
        ];
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
        Cache::put(self::CACHE_KEY, $switches, now()->addSeconds(self::CACHE_TTL));
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
