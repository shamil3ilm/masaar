<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * License Validation Service.
 *
 * Validates API keys and checks license status/limits.
 * Uses caching for performance on hot path.
 */
class LicenseValidationService
{
    /**
     * Cache TTL for license data (in seconds).
     */
    private function getCacheTtl(): int
    {
        return (int) config('licensing.cache_ttl', 300); // 5 minutes
    }

    /**
     * Validate API key and secret, returning the license.
     *
     * Alias for validate() for semantic clarity in middleware.
     *
     * @throws LicenseException
     */
    public function validateAndGetLicense(string $apiKey, string $apiSecret): License
    {
        return $this->validate($apiKey, $apiSecret);
    }

    /**
     * Validate API key and secret.
     *
     * @throws LicenseException
     */
    public function validate(string $apiKey, string $apiSecret): License
    {
        // Check cache first
        $cacheKey = $this->getCacheKey($apiKey);
        $license = Cache::get($cacheKey);

        if (! $license) {
            $license = License::where('api_key', $apiKey)->first();

            if (! $license) {
                Log::warning('Invalid API key attempted', [
                    'api_key_prefix' => substr($apiKey, 0, 12).'...',
                ]);
                throw LicenseException::invalidApiKey();
            }

            // Cache the license
            Cache::put($cacheKey, $license, $this->getCacheTtl());
        }

        // Verify secret
        if (! $license->verifySecret($apiSecret)) {
            Log::warning('Invalid API secret attempted', [
                'license_id' => $license->id,
            ]);
            throw LicenseException::invalidApiSecret();
        }

        // Check status
        $this->checkLicenseStatus($license);

        return $license;
    }

    /**
     * Validate by API key only (for rate limiting checks).
     */
    public function validateKey(string $apiKey): License
    {
        $cacheKey = $this->getCacheKey($apiKey);
        $license = Cache::get($cacheKey);

        if (! $license) {
            $license = License::where('api_key', $apiKey)->first();

            if (! $license) {
                throw LicenseException::invalidApiKey();
            }

            Cache::put($cacheKey, $license, $this->getCacheTtl());
        }

        $this->checkLicenseStatus($license);

        return $license;
    }

    /**
     * Check license status and expiry.
     *
     * @throws LicenseException
     */
    private function checkLicenseStatus(License $license): void
    {
        // Check if revoked
        if ($license->status === LicenseStatus::Revoked) {
            throw LicenseException::revoked($license->suspension_reason);
        }

        // Check if suspended
        if ($license->status === LicenseStatus::Suspended) {
            throw LicenseException::suspended($license->suspension_reason);
        }

        // Check expiry
        if ($license->isExpired()) {
            // Auto-update status to expired
            if ($license->status !== LicenseStatus::Expired) {
                $license->update(['status' => LicenseStatus::Expired]);
                $this->invalidateCache($license->api_key);
            }
            throw LicenseException::expired($license->expires_at);
        }

        // Check if pending activation
        if ($license->status === LicenseStatus::PendingActivation) {
            throw LicenseException::pendingActivation();
        }

        // Must be active
        if ($license->status !== LicenseStatus::Active) {
            throw LicenseException::inactive();
        }
    }

    /**
     * Check if license has a specific feature.
     */
    public function hasFeature(License $license, string $feature): bool
    {
        // Check feature flags
        if ($license->hasFeature($feature)) {
            return true;
        }

        // Check tier-based features
        return match ($feature) {
            'offline_mode' => $license->offline_mode,
            'multi_tenant' => $license->multi_tenant,
            'webhooks' => $license->webhook_enabled,
            default => false,
        };
    }

    /**
     * Check if license can add more organizations.
     */
    public function canAddOrganization(License $license, int $currentCount): bool
    {
        return $currentCount < $license->max_organizations;
    }

    /**
     * Get license info for display (without sensitive data).
     */
    public function getLicenseInfo(License $license): array
    {
        return [
            'id' => $license->id,
            'tier' => $license->tier->value,
            'tier_name' => $license->tier->getDisplayName(),
            'status' => $license->status->value,
            'organization_name' => $license->organization_name,
            'limits' => [
                'invoices_per_month' => $license->invoices_per_month,
                'organizations' => $license->max_organizations,
                'api_calls_per_minute' => $license->calls_per_min,
                'api_calls_per_day' => $license->calls_per_day,
            ],
            'features' => [
                'offline_mode' => $license->offline_mode,
                'multi_tenant' => $license->multi_tenant,
                'webhooks' => $license->webhook_enabled,
            ],
            'expires_at' => $license->expires_at?->toIso8601String(),
            'days_until_expiry' => $license->getDaysUntilExpiry(),
            'activated_at' => $license->activated_at?->toIso8601String(),
        ];
    }

    /**
     * Invalidate license cache.
     */
    public function invalidateCache(string $apiKey): void
    {
        Cache::forget($this->getCacheKey($apiKey));
    }

    /**
     * Get cache key for license.
     */
    private function getCacheKey(string $apiKey): string
    {
        return 'license:'.hash('sha256', $apiKey);
    }
}
