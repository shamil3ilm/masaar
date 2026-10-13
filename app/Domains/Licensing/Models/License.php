<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Models;

use App\Domains\Licensing\Enums\ApiScope;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 * @property int $invoices_per_month
 * @property int $max_organizations
 * @property int $calls_per_min
 * @property int $calls_per_day
 * @property array|null $features
 * @property array|null $scopes
 * @property bool $offline_mode
 * @property bool $multi_tenant
 * @property bool $webhook_enabled
 * @property LicenseStatus $status
 * @property Carbon|null $activated_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $suspended_at
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
        'org_id',
        'organization_name',
        'organization_vat',
        'contact_email',
        'contact_phone',
        'tier',
        'invoices_per_month',
        'max_organizations',
        'calls_per_min',
        'calls_per_day',
        'features',
        'scopes',
        'offline_mode',
        'multi_tenant',
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
        'offline_mode' => 'boolean',
        'multi_tenant' => 'boolean',
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
                'Licenses cannot be permanently deleted. Use soft delete (revoke) instead. '.
                'This is a compliance requirement to preserve audit trails.'
            );
        });

        // Validation caches the licence and reads its status off the cached
        // copy, so a suspension that left the entry in place kept
        // authenticating until it expired — five minutes of a credential
        // somebody has just had taken away.
        //
        // Done here rather than in each mutating service so a path added later
        // cannot forget. The original key is dropped too: rotating the key
        // itself would otherwise leave the old one usable.
        $forget = function (self $license): void {
            // Which keys to drop is decided now, while the model still knows
            // what its api_key was before this save.
            $keys = [$license->cacheKey()];

            $previous = $license->getOriginal('api_key');

            if (is_string($previous) && $previous !== $license->api_key) {
                $keys[] = self::cacheKeyFor($previous);
            }

            // Dropped after the commit, not before it. Registered on saved(),
            // this runs inside changeWithAudit's transaction, and the commit
            // only happens once that closure returns - an audit INSERT later.
            // Forgetting the entry in that gap achieves nothing: another
            // connection still reads the pre-commit row, finds it Active, and
            // caches that copy for the whole TTL. The revoked credential then
            // kept authenticating for five more minutes, which is the exact
            // thing this hook was added to prevent.
            //
            // afterCommit runs the callback immediately when no transaction is
            // open, so the callers that mutate outside one are unaffected.
            DB::afterCommit(function () use ($keys): void {
                foreach ($keys as $key) {
                    Cache::forget($key);
                }
            });
        };

        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
    }

    /**
     * Where validation keeps this licence.
     *
     * Derived in one place: the invalidation that existed forgot
     * "license:{api_key}" while the cache stores the digest of the key, so it
     * cleared nothing and both halves looked right on their own.
     */
    public function cacheKey(): string
    {
        return self::cacheKeyFor((string) $this->api_key);
    }

    public static function cacheKeyFor(string $apiKey): string
    {
        return 'license:'.hash('sha256', $apiKey);
    }

    /**
     * Generate a new API key for a specific environment.
     */
    public static function generateApiKey(?LicenseEnvironment $environment = null): string
    {
        $environment = $environment ?? LicenseEnvironment::Sandbox;
        $prefix = $environment->getApiKeyPrefix();

        return $prefix.Str::random(48);
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

        // Apply tier defaults if not specified.
        //
        // The key is read through ?? on both branches. It was read directly on
        // the first, so omitting it - which the comment above says is allowed -
        // raised "Undefined array key 'tier'". The ?? that looked like the
        // guard only ever protected the branch that is not reached when the
        // key is missing. PHPUnit turned that warning into a failure while
        // production logged it and carried on, so the suite and the deployment
        // disagreed about what this does.
        $given = $attributes['tier'] ?? null;

        $tier = $given instanceof LicenseTier
            ? $given
            : (LicenseTier::tryFrom((string) ($given ?? 'starter')) ?? LicenseTier::Starter);
        $defaults = $tier->getDefaults();

        // Get default scopes for tier if not provided
        $scopes = $attributes['scopes'] ?? ApiScope::getDefaultsForTier($tier);

        $license = self::create([
            'api_key' => $apiKey,
            'api_secret_hash' => self::hashSecret($apiSecret),
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
     * Hash an API secret for storage.
     *
     * Keyed on a pepper held outside the database, so reading the licences
     * table is not on its own enough to attack the hashes offline. HMAC rather
     * than a concatenated digest because that is the construction designed for
     * a keyed hash.
     *
     * This is the only place a secret is hashed. It was three, which is how
     * the pepper came to be declared in config/security.php, described in
     * .env.example, and read nowhere.
     */
    public static function hashSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, (string) config('security.api_key_pepper'));
    }

    /**
     * Verify an API secret.
     *
     * hash_equals compares in time independent of where the two differ, so a
     * caller cannot recover the stored hash a byte at a time by measuring.
     */
    public function verifySecret(string $secret): bool
    {
        return hash_equals($this->api_secret_hash, self::hashSecret($secret));
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
        if (! is_array($this->features)) {
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

        if (! is_array($this->scopes)) {
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
            if (! $this->hasScope($scope)) {
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
        if (! $this->expires_at) {
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
        // Revoked is terminal, and suspending it would not be a no-op: it
        // would move the licence into a status canReactivate() accepts, so
        // revoke -> suspend -> reactivate walks a revoked partner back to
        // Active in three clicks. Guarding reactivate() alone left that open.
        $this->changeWithAudit([
            'status' => LicenseStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ], 'suspended', $actorId, new: ['reason' => $reason], guard: function (LicenseStatus $status): void {
            if ($status === LicenseStatus::Revoked) {
                throw new \RuntimeException(
                    'A revoked licence cannot be suspended. Revocation is permanent; '
                    .'issue a new licence instead.'
                );
            }
        });
    }

    /**
     * Reactivate the license.
     *
     * Only from a status that may come back. LicenseStatus::canReactivate()
     * has encoded that rule - Suspended or Expired, never Revoked - since it
     * was written, and nothing called it: this method set Active
     * unconditionally, so a licence revoked for fraud, breach or non-payment
     * could be restored by anyone who could reach the admin console or
     * POST /licensing/{id}/reactivate.
     *
     * Revocation is the decision you do not want undone by a misclick.
     * Suspension is the reversible one and exists for that. The remedy for a
     * revoked partner is a new licence, which leaves a record that one was
     * issued.
     *
     * @throws \RuntimeException
     */
    public function reactivate(?string $actorId = null): void
    {
        $this->changeWithAudit([
            'status' => LicenseStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ], 'reactivated', $actorId, guard: function (LicenseStatus $status): void {
            if (! $status->canReactivate()) {
                throw new \RuntimeException(
                    'A licence that is '.$status->value.' cannot be reactivated. '
                    .'Revocation is permanent; issue a new licence instead.'
                );
            }
        });
    }

    /**
     * Revoke the license (permanent).
     */
    public function revoke(?string $reason = null, ?string $actorId = null): void
    {
        $this->changeWithAudit([
            'status' => LicenseStatus::Revoked,
            'suspension_reason' => $reason,
        ], 'revoked', $actorId, new: ['reason' => $reason]);
    }

    /**
     * Extend license expiry.
     */
    public function extend(int $days, ?string $actorId = null): void
    {
        // Computed inside the transaction, from the locked row.
        //
        // This read $this->expires_at first and handed changeWithAudit a
        // fixed date, so two administrators each adding thirty days both
        // started from the same expiry and the second write replaced the
        // first. Adding days to whatever the row currently holds makes two
        // extensions add up, which is what was asked for both times.
        $this->changeWithAudit(
            fn (self $locked): array => ['expires_at' => ($locked->expires_at ?? now())->addDays($days)],
            'extended',
            $actorId,
            old: fn (self $locked): array => ['expires_at' => $locked->expires_at?->toIso8601String()],
            new: fn (self $locked, array $changes): array => [
                'expires_at' => $changes['expires_at']->toIso8601String(),
                'days_added' => $days,
            ],
        );
    }

    /**
     * Upgrade to a new tier.
     */
    public function upgradeTier(LicenseTier $newTier, ?string $actorId = null): void
    {
        // The tier it was actually on, read under the lock. $this->tier was
        // loaded before the transaction, so a concurrent change made the
        // old_values of this entry describe a transition that never happened.
        $this->changeWithAudit(
            ['tier' => $newTier, ...$newTier->getDefaults()],
            'tier_changed',
            $actorId,
            old: fn (self $locked): array => ['tier' => $locked->tier->value],
            new: ['tier' => $newTier->value],
        );
    }

    /**
     * Apply a change and append its audit entry in one transaction.
     *
     * The entry records who changed the licence and why, so the change must
     * not persist without it, and the entry must not persist for a change
     * that did not.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    /**
     * Apply a change and record it, deciding from the row under lock.
     *
     * $changes, $old and $new may each be a closure instead of an array, and
     * are then called with the locked row and the resolved changes. That is
     * not a convenience: extend() computed its new expiry from a copy of the
     * licence loaded before this transaction opened, so two administrators
     * each adding thirty days both read the same expiry, both computed the
     * same result, and the second write silently replaced the first. The
     * customer paid for sixty days and got thirty, with two audit rows each
     * claiming thirty had been added.
     *
     * The re-read is unconditional. It used to happen only when a caller
     * passed a $guard, and revoke(), extend() and upgradeTier() did not -
     * which made the lock an opt-in for the callers least likely to think of
     * it. No caller benefits from deciding on a stale copy.
     *
     * @param  array<string, mixed>|Closure(self, array<string, mixed>): array<string, mixed>  $changes
     * @param  array<string, mixed>|Closure(self, array<string, mixed>): array<string, mixed>|null  $old
     * @param  array<string, mixed>|Closure(self, array<string, mixed>): array<string, mixed>|null  $new
     */
    private function changeWithAudit(
        array|Closure $changes,
        string $event,
        ?string $actorId,
        array|Closure|null $old = null,
        array|Closure|null $new = null,
        ?callable $guard = null,
    ): void {
        DB::transaction(function () use ($changes, $event, $actorId, $old, $new, $guard): void {
            // Re-read under a row lock before deciding anything.
            //
            // lockForUpdate is a no-op on SQLite, so the unit suite does not
            // exercise the lock itself; MySQL and Postgres hold the row.
            $current = static::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->setAttribute('status', $current->status);

            $changes = $changes instanceof Closure ? $changes($current, []) : $changes;
            $old = $old instanceof Closure ? $old($current, $changes) : $old;
            $new = $new instanceof Closure ? $new($current, $changes) : $new;

            if ($guard !== null) {
                // The status a guard was checked against was loaded before
                // this transaction, and another administrator may have moved
                // it since. Two people with the same licence open, one
                // revoking and one suspending, could otherwise have the
                // suspend overwrite the revoke - putting the licence back
                // into a status canReactivate() accepts, which is precisely
                // the invariant the guard exists to hold.
                $guard($current->status);
            }

            $this->update($changes);

            $this->auditLogs()->create([
                'event' => $event,
                // Falls back to the signed-in user rather than recording
                // 'system'. Nothing in the console passed an actor, so
                // suspend, revoke and reactivate - the actions worth asking
                // "who did this" about - were the unattributed ones, while
                // create and rotate were attributed because logAudit() has
                // always fallen back this way. Resolved here so every caller
                // gets it, including the API.
                //
                // 'user', not 'admin': SubmissionLedger and
                // ProcessFatooraSubmission already write user/system, and one
                // population of actors under two labels is a filter that
                // silently misses half of them. Which user it was is the
                // actor_id; what they are is on the user.
                'actor_type' => ($actorId ??= Auth::id()) ? 'user' : 'system',
                'actor_id' => $actorId,
                'old_values' => $old,
                'new_values' => $new,
                'created_at' => now(),
            ]);
        });
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
