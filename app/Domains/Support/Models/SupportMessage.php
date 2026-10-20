<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Support\Enums\RequestStage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message on a ticket: the customer, a reply from support, or a note
 * between admins the customer never sees. A reply that moved a request to
 * another stage says which.
 */
class SupportMessage extends Model
{
    use HasUuids;

    /** Written once and never changed. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'from_staff',
        'internal',
        'stage',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'from_staff' => 'boolean',
            'internal' => 'boolean',
            'stage' => RequestStage::class,
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'message_id');
    }
}
