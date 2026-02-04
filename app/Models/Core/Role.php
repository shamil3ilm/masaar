<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory, BelongsToOrganization, HasAuditTrail;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    // Relationships
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('branch_id')
            ->withTimestamps();
    }

    // Methods
    public function givePermissionTo(Permission|string|array $permissions): void
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        $permissionIds = collect($permissions)->map(function ($permission) {
            if ($permission instanceof Permission) {
                return $permission->id;
            }

            return Permission::where('slug', $permission)->firstOrFail()->id;
        });

        $this->permissions()->syncWithoutDetaching($permissionIds);
    }

    public function revokePermissionTo(Permission|string|array $permissions): void
    {
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        $permissionIds = collect($permissions)->map(function ($permission) {
            if ($permission instanceof Permission) {
                return $permission->id;
            }

            return Permission::where('slug', $permission)->first()?->id;
        })->filter();

        $this->permissions()->detach($permissionIds);
    }

    public function syncPermissions(array $permissions): void
    {
        $permissionIds = collect($permissions)->map(function ($permission) {
            if ($permission instanceof Permission) {
                return $permission->id;
            }

            if (is_int($permission)) {
                return $permission;
            }

            return Permission::where('slug', $permission)->first()?->id;
        })->filter();

        $this->permissions()->sync($permissionIds);
    }

    public function hasPermission(string $permissionSlug): bool
    {
        return $this->permissions->contains('slug', $permissionSlug);
    }
}
