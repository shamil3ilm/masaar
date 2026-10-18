<?php

declare(strict_types=1);

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An invitation to become a platform admin, until it is accepted or lapses.
 *
 * @property string $email
 * @property string $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class AdminInvitation extends Model
{
    use HasUuids;

    public const VALID_DAYS = 3;

    protected $fillable = [
        'email',
        'role',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
        'accepted_by',
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
