<?php

namespace App\Models;

use App\Domains\Organization\Models\Organization;
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
        ];
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
}
