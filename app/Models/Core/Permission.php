<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'module',
        'description',
    ];

    // Relationships
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withTimestamps();
    }

    // Scopes
    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    // Static Methods
    public static function getModules(): array
    {
        return static::distinct()->pluck('module')->toArray();
    }

    public static function getByModule(): array
    {
        return static::all()
            ->groupBy('module')
            ->map(fn ($permissions) => $permissions->pluck('name', 'slug'))
            ->toArray();
    }
}
