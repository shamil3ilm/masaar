<?php

declare(strict_types=1);

namespace App\Domains\Auth\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * API Key model for server-to-server authentication.
 *
 * Provides an alternative to JWT for automated integrations
 * where user login is not practical.
 */
class ApiKey extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'key_prefix',
        'key_hash',
        'scopes',
        'is_active',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Organization that owns this API key.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Generate a new API key.
     *
     * @return array{model: ApiKey, plain_key: string}
     */
    public static function generate(string $organizationId, string $name, array $scopes = ['*'], ?\DateTimeInterface $expiresAt = null): array
    {
        // Generate a random key with prefix for identification
        $prefix = 'cpk_'.Str::random(8);
        $secret = Str::random(40);
        $plainKey = $prefix.'_'.$secret;

        $model = static::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'key_prefix' => $prefix,
            'key_hash' => self::hashKey($plainKey),
            'scopes' => $scopes,
            'is_active' => true,
            'expires_at' => $expiresAt,
        ]);

        return [
            'model' => $model,
            'plain_key' => $plainKey,
        ];
    }

    /**
     * Find API key by plain text key.
     *
     * Runs outside the tenant scope on purpose: this lookup is what
     * establishes the tenant, so scoping it to the tenant would mean no key
     * could ever be found and every API-key request would fail to
     * authenticate. The key hash is the credential, and it is unique.
     *
     * Expiry is filtered in SQL rather than checked afterwards in PHP, so a
     * caller that uses this directly cannot be handed an expired key.
     */
    public static function findByKey(string $plainKey): ?self
    {
        return static::withoutTenantScope(fn () => static::query()
            ->where('key_hash', self::hashKey($plainKey))
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first());
    }

    /**
     * Hash a key for storage and lookup.
     *
     * Peppered with a secret held outside the database, so a leaked table of
     * hashes cannot be attacked offline without also compromising the
     * application's configuration. Keys carry 40 characters of entropy, which
     * is the primary control; the pepper is defence in depth.
     *
     * With no pepper configured this is a plain SHA-256, which keeps existing
     * keys working. Setting one invalidates every key issued before it.
     */
    private static function hashKey(string $plainKey): string
    {
        $pepper = (string) config('security.api_key_pepper', '');

        return $pepper === ''
            ? hash('sha256', $plainKey)
            : hash_hmac('sha256', $plainKey, $pepper);
    }

    /**
     * Check if key has a specific scope.
     */
    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    /**
     * Check if key is valid (active and not expired).
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Record usage.
     */
    public function recordUsage(): void
    {
        // last_used_at answers "is this key still in use", which needs
        // minute resolution at most. Writing it on every request puts a
        // synchronous UPDATE on the hot path and makes one busy key a source
        // of row contention. A short cache marker collapses a minute's worth
        // of requests into a single write.
        $marker = "api_key:used:{$this->id}";

        if (Cache::get($marker) !== null) {
            return;
        }

        Cache::put($marker, true, now()->addMinute());

        static::withoutTenantScope(fn () => static::query()
            ->whereKey($this->id)
            ->update(['last_used_at' => now()]));
    }
}
