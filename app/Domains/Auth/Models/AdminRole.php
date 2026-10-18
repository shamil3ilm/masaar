<?php

declare(strict_types=1);

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform admin role: one of the four system roles, or a custom one made
 * from the permission set. Read through AdminRoles.
 *
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property list<string> $permissions
 * @property int $rank
 * @property bool $system
 */
class AdminRole extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'name',
        'description',
        'permissions',
        'rank',
        'system',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'rank' => 'integer',
            'system' => 'boolean',
        ];
    }
}
