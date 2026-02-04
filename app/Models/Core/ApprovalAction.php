<?php

declare(strict_types=1);

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalAction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DELEGATED = 'delegated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'approval_request_id',
        'workflow_step_id',
        'assigned_to',
        'status',
        'delegated_to',
        'delegated_at',
        'comments',
        'action_at',
        'action_by',
        'expires_at',
        'reminder_sent',
    ];

    protected $casts = [
        'delegated_at' => 'datetime',
        'action_at' => 'datetime',
        'expires_at' => 'datetime',
        'reminder_sent' => 'boolean',
    ];

    // Relationships

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflowStep::class, 'workflow_step_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function delegatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_to');
    }

    public function actionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_by');
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('assigned_to', $userId)
                ->orWhere('delegated_to', $userId);
        });
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    public function scopeNeedsReminder($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('reminder_sent', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->addHours(24));
    }

    // Helper Methods

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isDelegated(): bool
    {
        return $this->status === self::STATUS_DELEGATED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED ||
            ($this->isPending() && $this->expires_at && $this->expires_at->lt(now()));
    }

    /**
     * Check if user can take action.
     */
    public function canActBy(int $userId): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        return $this->assigned_to === $userId ||
            $this->delegated_to === $userId;
    }

    /**
     * Approve this action.
     */
    public function approve(?string $comments = null, ?int $actionBy = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'comments' => $comments,
            'action_at' => now(),
            'action_by' => $actionBy ?? auth()->id(),
        ]);
    }

    /**
     * Reject this action.
     */
    public function reject(?string $comments = null, ?int $actionBy = null): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'comments' => $comments,
            'action_at' => now(),
            'action_by' => $actionBy ?? auth()->id(),
        ]);
    }

    /**
     * Delegate this action.
     */
    public function delegate(int $delegateTo, ?int $delegatedBy = null): void
    {
        $this->update([
            'delegated_to' => $delegateTo,
            'delegated_at' => now(),
            'action_by' => $delegatedBy ?? auth()->id(),
        ]);
    }

    /**
     * Mark as expired.
     */
    public function markExpired(): void
    {
        $this->update([
            'status' => self::STATUS_EXPIRED,
            'action_at' => now(),
        ]);
    }

    /**
     * Get effective assignee (considering delegation).
     */
    public function getEffectiveAssignee(): int
    {
        return $this->delegated_to ?? $this->assigned_to;
    }
}
