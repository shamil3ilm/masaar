<?php

declare(strict_types=1);

namespace App\Domains\Auth\Models;

use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * API Key model for server-to-server authentication.
 *
 * Provides an alternative to JWT for automated integrations
 * where user login is not practical.
 */
class ApiKey extends Model
{
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
        $prefix = 'cpk_' . Str::random(8);
        $secret = Str::random(40);
        $plainKey = $prefix . '_' . $secret;

        $model = static::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'key_prefix' => $prefix,
            'key_hash' => hash('sha256', $plainKey),
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
     */
    public static function findByKey(string $plainKey): ?self
    {
        $hash = hash('sha256', $plainKey);

        return static::where('key_hash', $hash)
            ->where('is_active', true)
            ->first();
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
        $this->update(['last_used_at' => now()]);
    }
}
