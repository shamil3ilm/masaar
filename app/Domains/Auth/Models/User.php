<?php

namespace App\Domains\Auth\Models;

use App\Domains\Organization\Models\Organization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * User model with JWT authentication support.
 *
 * Users can belong to multiple organizations (multi-tenant).
 * JWT token represents the user identity, not the organization.
 */
class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasUuids, Notifiable;

    /**
     * `is_platform_admin` is deliberately absent: it is a cross-tenant
     * privilege and must never be settable from request input.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    /**
     * Whether the account may use the Masaar-internal admin console.
     *
     * Distinct from the per-organization `admin` pivot role, which only
     * confers administration of that single tenant.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->status === 'active' && $this->is_platform_admin === true;
    }

    /**
     * Stated explicitly because the model lives outside App\Models, so
     * Laravel's namespace convention would look for the factory under
     * Database\Factories\Domains\Auth\Models.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * JWT identifier (user UUID).
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Custom claims added to JWT payload.
     * Organization context is added at login time, not here.
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /**
     * Organizations this user belongs to.
     * Pivot contains role and membership status.
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    /**
     * Organizations this user may currently act for.
     *
     * This is the authoritative set for portal tenant selection — a tenant
     * identifier is only ever accepted if it appears here.
     */
    public function activeOrganizations(): BelongsToMany
    {
        return $this->organizations()->wherePivot('status', 'active');
    }

    /**
     * Whether the user holds an active membership of the given organization.
     */
    public function belongsToOrganization(string $organizationId): bool
    {
        return $this->activeOrganizations()
            ->whereKey($organizationId)
            ->exists();
    }
}
