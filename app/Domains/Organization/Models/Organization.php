<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Organization entity (tenant).
 *
 * Represents a company/tenant in the multi-org system.
 * ZATCA compliance is scoped per organization.
 */
class Organization extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'country',
        'status',
        'compliance_profile',
    ];

    protected function casts(): array
    {
        return [
            'compliance_profile' => 'array',
        ];
    }

    /**
     * Users belonging to this organization.
     * Pivot contains role (admin, member) and membership status.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }
}
