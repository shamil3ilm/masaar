<?php

declare(strict_types=1);

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * An invitation to become a platform admin, until it is accepted, lapses or
 * is withdrawn - soft-deleted then, with who withdrew it.
 *
 * Who invited, accepted, made, changed and withdrew it are set by
 * AdminInvitations and the hooks below, never mass-assigned.
 *
 * @property string $email
 * @property string $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class AdminInvitation extends Model
{
    use HasUuids;
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

    protected static function booted(): void
    {
        static::creating(fn (self $invitation) => $invitation->created_by ??= Auth::id());
        // A service that knows who asked says so; otherwise the signed-in admin.
        static::saving(function (self $invitation): void {
            if (! $invitation->isDirty('updated_by')) {
                $invitation->updated_by = Auth::id() ?? $invitation->updated_by;
            }
        });
        static::deleting(fn (self $invitation) => $invitation->forceFill(['deleted_by' => Auth::id()])->saveQuietly());
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
