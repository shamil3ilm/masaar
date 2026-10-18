<?php

declare(strict_types=1);

namespace App\Domains\Auth\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A platform admin role: one of the four system roles, or a custom one made
 * from the permission set. Read through AdminRoles.
 *
 * A deleted custom role is soft-deleted, with who deleted it; who made and
 * last changed one is filled from the signed-in admin. None of the three
 * is mass-assignable.
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
    use RecordsActors;
    use SoftDeletes;

    protected $fillable = [
        'key',
        'name',
        'description',
        'permissions',
        'rank',
        'system',
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
