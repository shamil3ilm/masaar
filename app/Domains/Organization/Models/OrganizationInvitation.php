<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An invitation to join an organization, until it is accepted or lapses.
 */
class OrganizationInvitation extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    /** The roles an invitation can carry (MemberRole). */
    public const ROLES = ['owner', 'admin', 'member', 'accountant', 'viewer'];

    public const VALID_DAYS = 7;

    protected $fillable = [
        'org_id',
        'email',
        'role',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $hidden = ['token_hash'];

    /**
     * Who sent it is who created it.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'invited_by', 'updated_by' => 'updated_by', 'deleted_by' => 'deleted_by'];
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
