<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\DTOs\Credential;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\ApiKey;
use App\Domains\Licensing\Models\License;
use App\Support\LogSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        // licensing.cache.ttl_seconds, which LICENSE_CACHE_TTL sets. This read
        // licensing.cache_ttl, a key config/licensing.php does not have, so
        // the setting changed nothing.
        return (int) config('licensing.cache.ttl_seconds', 300);
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
     * Who a key and secret are: an organization's own API key, acting under
     * its licence, or a licence's key.
     *
     * The organization's keys are looked for first, by their unique index
     * and never cached: revoking one has to stop it on the next call. A key
     * that is neither falls through to validate(), which refuses it as it
     * always has.
     *
     * @throws LicenseException
     */
    public function authenticate(string $apiKey, string $apiSecret, ?string $address = null): Credential
    {
        $key = ApiKey::withoutTenantScope(fn (): ?ApiKey => ApiKey::query()->where('api_key', $apiKey)->first());

        if ($key === null) {
            return new Credential($this->validate($apiKey, $apiSecret));
        }

        if (! $key->verifySecret($apiSecret)) {
            Log::warning('Invalid API key secret attempted', ['api_key_id' => $key->id]);

            throw LicenseException::invalidApiSecret();
        }

        if (! $key->isActive()) {
            throw LicenseException::keyPendingApproval();
        }

        if ($key->isExpired()) {
            throw LicenseException::keyExpired($key->expires_at);
        }

        if (! $key->allowsAddress($address)) {
            throw LicenseException::addressNotAllowed($address);
        }

        // The organization's licence is what the call is allowed and
        // metered by. With none valid, its keys reach nothing.
        $licence = $this->meteringLicence((string) $key->org_id);

        if ($licence === null) {
            throw LicenseException::inactive();
        }

        return new Credential($licence, $key);
    }

    /**
     * The licence an organization's own API keys act under: the first it
     * was given of those it can use.
     */
    public function meteringLicence(string $organizationId): ?License
    {
        return $this->licensed($organizationId)->orderBy('created_at')->orderBy('id')->first();
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
                // A hash, not the first twelve characters.
                //
                // The key is cp_live_ or cp_test_ plus 48 random characters,
                // so twelve of them is the environment prefix and four bytes
                // of entropy - written at warning level on every failed
                // attempt, which an unauthenticated caller controls, and
                // written from a real key whenever a legitimate one is
                // mistyped or has just been rotated out. The hash still
                // correlates repeated attempts, which is the only reason to
                // log anything here. LogSanitizer::hashForLog exists for
                // exactly this and had no callers.
                Log::warning('Invalid API key attempted', [
                    'api_key_hash' => LogSanitizer::hashForLog($apiKey),
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
     * Invalidate license cache.
     */
    /**
     * Whether an organization holds a licence it can file under: active, and
     * not past its expiry.
     */
    public function organizationIsLicensed(string $organizationId): bool
    {
        return $this->licensed($organizationId)->exists();
    }

    /**
     * The licences an organization holds and can use: active, and not past
     * their expiry. Its plan is what they grant between them.
     *
     * @return Collection<int, License>
     */
    public function organizationLicences(string $organizationId): Collection
    {
        return $this->licensed($organizationId)->get();
    }

    /**
     * @return Builder<License>
     */
    private function licensed(string $organizationId): Builder
    {
        return License::query()
            ->active()
            ->where('org_id', $organizationId)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function invalidateCache(string $apiKey): void
    {
        Cache::forget($this->getCacheKey($apiKey));
    }

    /**
     * Get cache key for license.
     */
    private function getCacheKey(string $apiKey): string
    {
        return License::cacheKeyFor($apiKey);
    }
}
