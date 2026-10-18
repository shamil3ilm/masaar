<?php

declare(strict_types=1);

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The owner offering the platform's ownership to another admin, and what
 * became of it: pending, accepted, cancelled or expired.
 *
 * Evidence of who held the platform when: never deleted, and every actor
 * set by PlatformOwnership, none mass-assigned.
 *
 * @property string $id
 * @property string $from_user_id
 * @property string $to_user_id
 * @property string $demote_to
 * @property string $status
 * @property Carbon $expires_at
 */
class OwnerTransfer extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const CANCELLED = 'cancelled';

    public const VALID_HOURS = 72;

    protected $fillable = [
        'demote_to',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }
}
