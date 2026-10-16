<?php

namespace App\Domains\Auth\Models;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Organization\Models\Organization;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
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
class User extends Authenticatable implements JWTSubject, MustVerifyEmailContract
{
    use HasFactory, HasUuids, MustVerifyEmail, Notifiable;

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
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_enrolment_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            // Encrypted at rest: the secret is the second factor, and stored
            // in the clear it is not a second factor for anyone who can read
            // the database.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_enrolment_expires_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password_changed_at' => 'datetime',
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
        return $this->isActive() && $this->is_platform_admin === true;
    }

    /**
     * What this platform admin is for, or null for anyone who is not one.
     *
     * An admin with no role recorded - made before roles existed, or by the
     * server's masaar:create-admin - is an owner, which is what every admin
     * was until roles existed. The console's Admins page always sets one.
     */
    public function platformRole(): ?PlatformRole
    {
        if (! $this->isPlatformAdmin()) {
            return null;
        }

        // Only a missing role means owner. A value that is not a role - a typo,
        // a role since retired - is no role at all, not the most powerful one.
        return $this->platform_role === null ? PlatformRole::Owner : PlatformRole::tryFrom((string) $this->platform_role);
    }

    /**
     * Whether this admin may act on another account's access - its second
     * factor, its enrolment link, its suspension. Any account that is not an
     * admin, yes; an admin, only from an owner or a higher rank.
     */
    public function mayActOnAccessOf(User $target): bool
    {
        $mine = $this->platformRole();

        if ($mine === null) {
            return false;
        }

        $theirs = $target->platformRole();

        return $theirs === null || $mine === PlatformRole::Owner || $mine->rank() > $theirs->rank();
    }

    /**
     * Whether this account may do one thing in the admin console or API.
     */
    public function canAdmin(AdminPermission|string $permission): bool
    {
        $permission = $permission instanceof AdminPermission ? $permission : AdminPermission::tryFrom($permission);

        return $permission !== null && ($this->platformRole()?->allows($permission) ?? false);
    }

    /**
     * Whether two-factor authentication is set up and confirmed.
     */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * Whether the account may be used at all. A suspended account is refused
     * at sign-in, and on its next request if it was already signed in.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
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
    /**
     * The organization this token acts for, when there is only one it could be.
     *
     * JwtGuard establishes the tenant from org_id and role, and this returned
     * an empty array — so the guard's condition was never true, no tenant was
     * ever set, and every route on the JWT surface ran unscoped.
     *
     * A user belonging to several organizations gets no claim, because nothing
     * here can choose for them; they call organizations/{id}/switch and receive
     * a token that carries the choice.
     */
    public function getJWTCustomClaims(): array
    {
        $memberships = $this->activeOrganizations()->get();

        if ($memberships->count() !== 1) {
            return [];
        }

        $organization = $memberships->first();

        return [
            'org_id' => $organization->id,
            'role' => $organization->pivot->role,
        ];
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

    /**
     * The user's role in one organization, or null without active membership.
     */
    public function roleIn(string $organizationId): ?string
    {
        $organization = $this->activeOrganizations()
            ->whereKey($organizationId)
            ->first();

        return $organization?->pivot->role;
    }
}
