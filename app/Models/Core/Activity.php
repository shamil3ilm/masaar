<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'user_id',
        'branch_id',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'event',
        'description',
        'properties',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'source',
    ];

    protected $casts = [
        'properties' => 'array',
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    // Common events
    public const EVENT_CREATED = 'created';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_DELETED = 'deleted';
    public const EVENT_RESTORED = 'restored';
    public const EVENT_VIEWED = 'viewed';
    public const EVENT_SENT = 'sent';
    public const EVENT_APPROVED = 'approved';
    public const EVENT_REJECTED = 'rejected';
    public const EVENT_POSTED = 'posted';
    public const EVENT_VOIDED = 'voided';
    public const EVENT_PAID = 'paid';
    public const EVENT_PARTIALLY_PAID = 'partially_paid';
    public const EVENT_PRINTED = 'printed';
    public const EVENT_EXPORTED = 'exported';
    public const EVENT_EMAILED = 'emailed';
    public const EVENT_COMMENTED = 'commented';
    public const EVENT_ASSIGNED = 'assigned';
    public const EVENT_STATUS_CHANGED = 'status_changed';
    public const EVENT_ATTACHMENT_ADDED = 'attachment_added';
    public const EVENT_ATTACHMENT_REMOVED = 'attachment_removed';

    // Sources
    public const SOURCE_WEB = 'web';
    public const SOURCE_API = 'api';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_MOBILE = 'mobile';

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes

    public function scopeForSubject($query, Model $subject)
    {
        return $query->where('subject_type', get_class($subject))
            ->where('subject_id', $subject->id);
    }

    public function scopeForEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeFromSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    // Helpers

    public function getChanges(): array
    {
        $changes = [];

        if (!$this->old_values || !$this->new_values) {
            return $changes;
        }

        foreach ($this->new_values as $key => $newValue) {
            $oldValue = $this->old_values[$key] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }

    public function hasChanges(): bool
    {
        return !empty($this->getChanges());
    }

    public function getFormattedDescription(): string
    {
        if ($this->description) {
            return $this->description;
        }

        $subjectType = class_basename($this->subject_type);
        $causerName = $this->user?->name ?? 'System';

        return match ($this->event) {
            self::EVENT_CREATED => "{$causerName} created this {$subjectType}",
            self::EVENT_UPDATED => "{$causerName} updated this {$subjectType}",
            self::EVENT_DELETED => "{$causerName} deleted this {$subjectType}",
            self::EVENT_SENT => "{$causerName} sent this {$subjectType}",
            self::EVENT_APPROVED => "{$causerName} approved this {$subjectType}",
            self::EVENT_REJECTED => "{$causerName} rejected this {$subjectType}",
            self::EVENT_POSTED => "{$causerName} posted this {$subjectType}",
            self::EVENT_PAID => "Payment received for this {$subjectType}",
            self::EVENT_EMAILED => "{$causerName} emailed this {$subjectType}",
            default => "{$causerName} performed {$this->event} on this {$subjectType}",
        };
    }

    public function getIcon(): string
    {
        return match ($this->event) {
            self::EVENT_CREATED => 'plus-circle',
            self::EVENT_UPDATED => 'edit',
            self::EVENT_DELETED => 'trash',
            self::EVENT_SENT => 'send',
            self::EVENT_APPROVED => 'check-circle',
            self::EVENT_REJECTED => 'x-circle',
            self::EVENT_POSTED => 'file-check',
            self::EVENT_PAID => 'dollar-sign',
            self::EVENT_EMAILED => 'mail',
            self::EVENT_PRINTED => 'printer',
            self::EVENT_COMMENTED => 'message-square',
            self::EVENT_ATTACHMENT_ADDED => 'paperclip',
            default => 'activity',
        };
    }

    public function getColor(): string
    {
        return match ($this->event) {
            self::EVENT_CREATED => 'green',
            self::EVENT_DELETED => 'red',
            self::EVENT_APPROVED, self::EVENT_PAID => 'green',
            self::EVENT_REJECTED => 'red',
            self::EVENT_SENT, self::EVENT_EMAILED => 'blue',
            default => 'gray',
        };
    }
}
