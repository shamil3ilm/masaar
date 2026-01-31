<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Models;

use App\Domains\Licensing\Enums\ApiScope;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * License model for API key management.
 *
 * ZATCA Compliance Notes:
 * - Suspension only blocks new operations, never mutates existing data
 * - Scopes control authorization, not data access
 * - Environment separation prevents cross-environment leakage
 *
 * @property string $id
 * @property string $api_key
 * @property string $api_secret_hash
 * @property LicenseEnvironment $environment
 * @property string $organization_name
 * @property string|null $organization_vat
 * @property string $contact_email
 * @property string|null $contact_phone
 * @property LicenseTier $tier
 * @property int $max_invoices_per_month
 * @property int $max_organizations
 * @property int $max_api_calls_per_minute
 * @property int $max_api_calls_per_day
 * @property array|null $features
 * @property array|null $scopes
 * @property bool $offline_mode_enabled
 * @property bool $multi_tenant_enabled
 * @property bool $webhook_enabled
 * @property LicenseStatus $status
 * @property \Carbon\Carbon|null $activated_at
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property string|null $issued_by
 * @property string|null $notes
 */
class License extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'licenses';

    protected $fillable = [
        'api_key',
        'api_secret_hash',
        'environment',
        'organization_name',
        'organization_vat',
        'contact_email',
        'contact_phone',
        'tier',
        'max_invoices_per_month',
        'max_organizations',
        'max_api_calls_per_minute',
        'max_api_calls_per_day',
        'features',
        'scopes',
        'offline_mode_enabled',
        'multi_tenant_enabled',
        'webhook_enabled',
        'status',
        'activated_at',
        'expires_at',
        'suspended_at',
        'suspension_reason',
        'issued_by',
        'notes',
    ];

    protected $casts = [
        'tier' => LicenseTier::class,
        'status' => LicenseStatus::class,
        'environment' => LicenseEnvironment::class,
        'features' => 'array',
        'scopes' => 'array',
        'offline_mode_enabled' => 'boolean',
        'multi_tenant_enabled' => 'boolean',
        'webhook_enabled' => 'boolean',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    protected $hidden = [
        'api_secret_hash',
    ];

    /**
     * Boot method - prevent hard deletion.
     *
     * COMPLIANCE: Licenses can be soft-deleted (revoked) but never
     * permanently removed. This preserves audit trail and usage history.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Prevent force deletion (hard delete)
        static::forceDeleting(function ($model) {
            throw new \RuntimeException(
                'Licenses cannot be permanently deleted. Use soft delete (revoke) instead. ' .
                'This is a compliance requirement to preserve audit trails.'
            );
        });
    }

    /**
     * Generate a new API key for a specific environment.
     */
    public static function generateApiKey(?LicenseEnvironment $environment = null): string
    {
        $environment = $environment ?? LicenseEnvironment::Sandbox;
        $prefix = $environment->getApiKeyPrefix();
        return $prefix . Str::random(48);
    }

    /**
     * Generate a new API secret.
     */
    public static function generateApiSecret(): string
    {
        return Str::random(64);
    }

    /**
     * Create a new license with generated credentials.
     *
     * @return array{license: License, api_key: string, api_secret: string}
     */
    public static function createWithCredentials(array $attributes): array
    {
        // Determine environment
        $environment = isset($attributes['environment'])
            ? (is_string($attributes['environment'])
                ? LicenseEnvironment::from($attributes['environment'])
                : $attributes['environment'])
            : LicenseEnvironment::Sandbox;

        $apiKey = self::generateApiKey($environment);
        $apiSecret = self::generateApiSecret();

        // Apply tier defaults if not specified
        $tier = $attributes['tier'] instanceof LicenseTier
            ? $attributes['tier']
            : (LicenseTier::tryFrom($attributes['tier'] ?? 'starter') ?? LicenseTier::Starter);
        $defaults = $tier->getDefaults();

        // Get default scopes for tier if not provided
        $scopes = $attributes['scopes'] ?? ApiScope::getDefaultsForTier($tier);

        $license = self::create([
            'api_key' => $apiKey,
            'api_secret_hash' => hash('sha256', $apiSecret),
            'environment' => $environment,
            'tier' => $tier,
            'scopes' => $scopes,
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
            ...$defaults,
            ...$attributes,
        ]);

        return [
            'license' => $license,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret, // Only returned once!
        ];
    }

    /**
     * Verify API secret using SHA256 hash comparison.
     *
     * Uses hash_equals for timing-safe comparison.
     */
    public function verifySecret(string $secret): bool
    {
        return hash_equals($this->api_secret_hash, hash('sha256', $secret));
    }

    /**
     * Check if license is active and valid.
     */
    public function isValid(): bool
    {
        if ($this->status !== LicenseStatus::Active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Check if license is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if a feature is enabled.
     */
    public function hasFeature(string $feature): bool
    {
        if (!is_array($this->features)) {
            return false;
        }

        // Support both array of strings and key-value pairs
        return in_array($feature, $this->features, true)
            || (isset($this->features[$feature]) && $this->features[$feature] === true);
    }

    /**
     * Check if license has a specific scope.
     *
     * Scopes control what operations this API key can perform.
     * This is pure authorization - never affects data.
     */
    public function hasScope(string|ApiScope $scope): bool
    {
        $scopeValue = $scope instanceof ApiScope ? $scope->value : $scope;

        if (!is_array($this->scopes)) {
            return false;
        }

        // Expand scopes to include implied ones
        $expandedScopes = ApiScope::expandScopes($this->scopes);

        return in_array($scopeValue, $expandedScopes, true);
    }

    /**
     * Check if license has all of the specified scopes.
     */
    public function hasAllScopes(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (!$this->hasScope($scope)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if license has any of the specified scopes.
     */
    public function hasAnyScope(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->hasScope($scope)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if this license can operate in the current request environment.
     *
     * ZATCA Compliance: Prevents sandbox keys from submitting real invoices.
     */
    public function matchesEnvironment(string $requestedEnv): bool
    {
        return $this->environment->value === $requestedEnv;
    }

    /**
     * Check if this license allows real ZATCA submissions.
     */
    public function allowsRealSubmissions(): bool
    {
        return $this->environment === LicenseEnvironment::Production;
    }

    /**
     * Get days until expiry.
     */
    public function getDaysUntilExpiry(): ?int
    {
        if (!$this->expires_at) {
            return null; // No expiry
        }

        return max(0, now()->diffInDays($this->expires_at, false));
    }

    /*
    |--------------------------------------------------------------------------
    | LICENSE SUSPENSION SEMANTICS
    |--------------------------------------------------------------------------
    |
    | IMPORTANT: Suspension and revocation behavior follows these rules:
    |
    | When a license is SUSPENDED:
    | - Submit new invoices to ZATCA:       BLOCKED
    | - Create new invoices (draft):        BLOCKED
    | - Query existing invoices:            ALLOWED (read-only)
    | - Retrieve audit logs:                ALLOWED (read-only)
    | - Export compliance data:             ALLOWED (for regulatory needs)
    | - Access dashboard/statistics:        ALLOWED (read-only)
    | - Webhook deliveries:                 PAUSED (queued for when reactivated)
    |
    | When a license is REVOKED:
    | - All API operations:                 BLOCKED
    | - Data remains accessible via admin tools for compliance
    | - No automatic data deletion (ZATCA compliance requirement)
    |
    | ZATCA COMPLIANCE CRITICAL:
    | - Suspension NEVER mutates or deletes existing invoice data
    | - Historical submissions remain intact regardless of license status
    | - Audit trail is preserved even for revoked licenses
    | - This is a legal requirement for tax compliance records
    |
    | These semantics should be documented in:
    | - Enterprise SLA agreements
    | - Terms of Service
    | - API documentation
    |
    */

    /**
     * Suspend the license.
     *
     * Blocks new operations but preserves read-only access to existing data.
     * Does NOT affect historical submissions or audit trails.
     */
    public function suspend(?string $reason = null, ?string $actorId = null): void
    {
        $this->update([
            'status' => LicenseStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);

        $this->auditLogs()->create([
            'id' => Str::uuid()->toString(),
            'event' => 'suspended',
            'actor_type' => $actorId ? 'admin' : 'system',
            'actor_id' => $actorId,
            'new_values' => ['reason' => $reason],
            'created_at' => now(),
        ]);
    }

    /**
     * Reactivate the license.
     */
    public function reactivate(?string $actorId = null): void
    {
        $this->update([
            'status' => LicenseStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        $this->auditLogs()->create([
            'id' => Str::uuid()->toString(),
            'event' => 'reactivated',
            'actor_type' => $actorId ? 'admin' : 'system',
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);
    }

    /**
     * Revoke the license (permanent).
     */
    public function revoke(?string $reason = null, ?string $actorId = null): void
    {
        $this->update([
            'status' => LicenseStatus::Revoked,
            'suspension_reason' => $reason,
        ]);

        $this->auditLogs()->create([
            'id' => Str::uuid()->toString(),
            'event' => 'revoked',
            'actor_type' => $actorId ? 'admin' : 'system',
            'actor_id' => $actorId,
            'new_values' => ['reason' => $reason],
            'created_at' => now(),
        ]);
    }

    /**
     * Extend license expiry.
     */
    public function extend(int $days, ?string $actorId = null): void
    {
        $oldExpiry = $this->expires_at;
        $newExpiry = ($this->expires_at ?? now())->addDays($days);

        $this->update([
            'expires_at' => $newExpiry,
        ]);

        $this->auditLogs()->create([
            'id' => Str::uuid()->toString(),
            'event' => 'extended',
            'actor_type' => $actorId ? 'admin' : 'system',
            'actor_id' => $actorId,
            'old_values' => ['expires_at' => $oldExpiry?->toIso8601String()],
            'new_values' => ['expires_at' => $newExpiry->toIso8601String(), 'days_added' => $days],
            'created_at' => now(),
        ]);
    }

    /**
     * Upgrade to a new tier.
     */
    public function upgradeTier(LicenseTier $newTier, ?string $actorId = null): void
    {
        $oldTier = $this->tier;
        $defaults = $newTier->getDefaults();

        $this->update([
            'tier' => $newTier,
            ...$defaults,
        ]);

        $this->auditLogs()->create([
            'id' => Str::uuid()->toString(),
            'event' => 'tier_changed',
            'actor_type' => $actorId ? 'admin' : 'system',
            'actor_id' => $actorId,
            'old_values' => ['tier' => $oldTier->value],
            'new_values' => ['tier' => $newTier->value],
            'created_at' => now(),
        ]);
    }

    // Relationships

    public function usageRecords(): HasMany
    {
        return $this->hasMany(LicenseUsage::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(LicenseAuditLog::class);
    }

    public function rateLimits(): HasMany
    {
        return $this->hasMany(LicenseRateLimit::class);
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    // Query Scopes

    public function scopeActive($query)
    {
        return $query->where('status', LicenseStatus::Active);
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('status', LicenseStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days));
    }
}
