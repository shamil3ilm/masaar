<?php

declare(strict_types=1);

namespace App\Domains\Auth\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An invitation to become a platform admin, until it is accepted, lapses or
 * is withdrawn - soft-deleted then, with who withdrew it.
 *
 * Who invited, accepted, made, changed and withdrew it are set by
 * AdminInvitations and RecordsActors, never mass-assigned.
 *
 * @property string $email
 * @property string $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class AdminInvitation extends Model
{
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    public const VALID_DAYS = 3;

    protected $fillable = [
        'email',
        'role',
        'token_hash',
        'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
