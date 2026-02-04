<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use BelongsToOrganization, HasUuid, SoftDeletes;

    protected $table = 'crm_activities';

    public const TYPE_CALL = 'call';
    public const TYPE_EMAIL = 'email';
    public const TYPE_MEETING = 'meeting';
    public const TYPE_TASK = 'task';
    public const TYPE_NOTE = 'note';
    public const TYPE_FOLLOW_UP = 'follow_up';

    public const STATUS_PLANNED = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';

    public const CALL_INBOUND = 'inbound';
    public const CALL_OUTBOUND = 'outbound';

    protected $fillable = [
        'organization_id',
        'activity_type',
        'subject',
        'description',
        'related_type',
        'related_id',
        'start_datetime',
        'end_datetime',
        'duration_minutes',
        'is_all_day',
        'status',
        'priority',
        'completed_at',
        'call_direction',
        'call_result',
        'location',
        'meeting_link',
        'assigned_to',
        'attendees',
        'reminder_datetime',
        'reminder_sent',
        'outcome',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_datetime' => 'datetime',
            'duration_minutes' => 'integer',
            'is_all_day' => 'boolean',
            'reminder_sent' => 'boolean',
            'attendees' => 'array',
        ];
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPlanned(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isOverdue(): bool
    {
        if ($this->isCompleted() || $this->isCancelled()) {
            return false;
        }

        $dueDate = $this->end_datetime ?? $this->start_datetime;
        return $dueDate && $dueDate->isPast();
    }

    public function complete(?string $outcome = null): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = now();
        if ($outcome) {
            $this->outcome = $outcome;
        }
        $this->save();
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->save();
    }

    public function getActivityTypeLabel(): string
    {
        return match ($this->activity_type) {
            self::TYPE_CALL => 'Call',
            self::TYPE_EMAIL => 'Email',
            self::TYPE_MEETING => 'Meeting',
            self::TYPE_TASK => 'Task',
            self::TYPE_NOTE => 'Note',
            self::TYPE_FOLLOW_UP => 'Follow-up',
            default => ucfirst($this->activity_type),
        };
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('activity_type', $type);
    }

    public function scopePlanned($query)
    {
        return $query->where('status', self::STATUS_PLANNED);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeOverdue($query)
    {
        return $query->whereIn('status', [self::STATUS_PLANNED, self::STATUS_IN_PROGRESS])
            ->where(function ($q) {
                $q->where('end_datetime', '<', now())
                    ->orWhere(function ($q2) {
                        $q2->whereNull('end_datetime')
                            ->where('start_datetime', '<', now());
                    });
            });
    }

    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->whereIn('status', [self::STATUS_PLANNED, self::STATUS_IN_PROGRESS])
            ->where('start_datetime', '>=', now())
            ->where('start_datetime', '<=', now()->addDays($days));
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeForRelated($query, string $type, int $id)
    {
        return $query->where('related_type', $type)->where('related_id', $id);
    }

    public function scopeWithPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', self::PRIORITY_HIGH);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('start_datetime', today());
    }

    public function scopeNeedingReminder($query)
    {
        return $query->where('reminder_sent', false)
            ->whereNotNull('reminder_datetime')
            ->where('reminder_datetime', '<=', now())
            ->whereIn('status', [self::STATUS_PLANNED, self::STATUS_IN_PROGRESS]);
    }
}
