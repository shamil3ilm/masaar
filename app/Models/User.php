<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use App\Models\Concerns\HasUuid;
use App\Models\Core\Branch;
use App\Models\Core\Notification;
use App\Models\Core\Organization;
use App\Models\Core\Permission;
use App\Models\Core\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject, \Illuminate\Contracts\Auth\MustVerifyEmail
{
    use HasFactory, Notifiable, SoftDeletes, HasUuid, HasAuditTrail;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'name',
        'email',
        'phone',
        'password',
        'preferred_language',
        'timezone',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'is_active',
        // is_super_admin is intentionally excluded from fillable to prevent mass-assignment privilege escalation.
        // Use direct DB assignment or a dedicated admin promotion method to set this field.
        'last_login_at',
        'last_login_ip',
        'email_verification_code',
        'email_verification_code_sent_at',
        'registration_source',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'referral_code',
        'registration_device_type',
        'registration_ip',
        'invited_by_user_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'email_verification_code',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'two_factor_enabled' => 'boolean',
        'two_factor_secret' => 'encrypted',
        'module_access' => 'array',
        'two_factor_recovery_codes' => 'array',
        'two_factor_confirmed_at' => 'datetime',
        'is_active' => 'boolean',
        'is_super_admin' => 'boolean',
        'last_login_at' => 'datetime',
        'email_verification_code_sent_at' => 'datetime',
    ];

    // Fields to exclude from audit
    protected array $auditExclude = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    // JWT Methods
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'organization_id' => $this->organization_id,
            'is_super_admin' => $this->is_super_admin,
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branches')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('branch_id')
            ->withTimestamps();
    }

    // Permission Methods
    public function hasPermission(string $permission, ?int $branchId = null): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        $roles = $this->roles()
            ->when($branchId, function ($query) use ($branchId) {
                $query->where(function ($q) use ($branchId) {
                    $q->whereNull('user_roles.branch_id')
                        ->orWhere('user_roles.branch_id', $branchId);
                });
            })
            ->with('permissions')
            ->get();

        foreach ($roles as $role) {
            if ($role->permissions->contains('slug', $permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyPermission(array $permissions, ?int $branchId = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission, $branchId)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(array $permissions, ?int $branchId = null): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission, $branchId)) {
                return false;
            }
        }

        return true;
    }

    public function hasRole(string $roleSlug): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->roles->contains('slug', $roleSlug);
    }

    public function getAllPermissions(): array
    {
        if ($this->is_super_admin) {
            return Permission::pluck('slug')->toArray();
        }

        return $this->roles()->with('permissions')->get()
            ->flatMap(fn (Role $role) => $role->permissions)
            ->unique('id')
            ->pluck('slug')
            ->toArray();
    }

    /**
     * Get current branch ID (accessor for convenience).
     */
    public function getCurrentBranchIdAttribute(): ?int
    {
        return $this->getDefaultBranch()?->id;
    }

    // Branch Methods
    public function getDefaultBranch(): ?Branch
    {
        return $this->branches()
            ->wherePivot('is_default', true)
            ->first()
            ?? $this->branches()->first();
    }

    public function setDefaultBranch(Branch $branch): void
    {
        // Remove default from all user branches
        $this->branches()->updateExistingPivot(
            $this->branches->pluck('id')->toArray(),
            ['is_default' => false]
        );

        // Set new default
        $this->branches()->updateExistingPivot($branch->id, ['is_default' => true]);
    }

    public function hasBranchAccess(int $branchId): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->branches()->where('branches.id', $branchId)->exists();
    }

    /**
     * Override the default Laravel notifications() to use our custom Notification model.
     * This ensures all database-channel notifications include organization_id automatically.
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    // Login tracking
    public function recordLogin(): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by_user_id');
    }
}
