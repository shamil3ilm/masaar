<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmailLog extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'user_id',
        'template_code',
        'emailable_type',
        'emailable_id',
        'to_email',
        'to_name',
        'subject',
        'body_preview',
        'attachments',
        'status',
        'error_message',
        'sent_at',
        'opened_at',
        'clicked_at',
        'bounced_at',
        'message_id',
    ];

    protected $casts = [
        'attachments' => 'array',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'bounced_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_OPENED = 'opened';
    public const STATUS_CLICKED = 'clicked';

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emailable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSent($query)
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeForDocument($query, string $type, int $id)
    {
        return $query->where('emailable_type', $type)->where('emailable_id', $id);
    }

    // Status updates

    public function markAsSent(?string $messageId = null): self
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'message_id' => $messageId,
        ]);
        return $this;
    }

    public function markAsFailed(string $error): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
        ]);
        return $this;
    }

    public function markAsOpened(): self
    {
        if (!$this->opened_at) {
            $this->update([
                'status' => self::STATUS_OPENED,
                'opened_at' => now(),
            ]);
        }
        return $this;
    }

    public function markAsClicked(): self
    {
        $this->update([
            'status' => self::STATUS_CLICKED,
            'clicked_at' => now(),
        ]);
        return $this;
    }

    public function markAsBounced(): self
    {
        $this->update([
            'status' => self::STATUS_BOUNCED,
            'bounced_at' => now(),
        ]);
        return $this;
    }

    // Helpers

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSent(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_OPENED, self::STATUS_CLICKED]);
    }

    public function isFailed(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_BOUNCED]);
    }

    public function wasOpened(): bool
    {
        return $this->opened_at !== null;
    }

    public function wasClicked(): bool
    {
        return $this->clicked_at !== null;
    }
}
