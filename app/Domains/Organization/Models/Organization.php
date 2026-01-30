<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Organization entity.
 *
 * Represents a tenant/organization in the multi-org system.
 * Authorization and scope boundary for users.
 */
class Organization extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'country',
        'compliance_profile',
    ];

    protected function casts(): array
    {
        return [
            'compliance_profile' => 'array',
        ];
    }
}
