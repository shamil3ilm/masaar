<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    use BelongsToOrganization, HasUuid;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id',
        'approval_workflow_id',
        'approvable_type',
        'approvable_id',
        'current_step_id',
        'status',
        'amount',
        'notes',
        'submitted_at',
        'completed_at',
        'submitted_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflowStep::class, 'current_step_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class);
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeForApprovable($query, string $type, int $id)
    {
        return $query->where('approvable_type', $type)->where('approvable_id', $id);
    }

    public function scopeAwaitingAction($query, int $userId)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_PROGRESS])
            ->whereHas('actions', function ($q) use ($userId) {
                $q->where('assigned_to', $userId)
                    ->where('status', ApprovalAction::STATUS_PENDING);
            });
    }

    // Helper Methods

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ]);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
        ]);
    }

    /**
     * Get pending actions for current step.
     */
    public function getPendingActions(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->actions()
            ->where('workflow_step_id', $this->current_step_id)
            ->where('status', ApprovalAction::STATUS_PENDING)
            ->get();
    }

    /**
     * Get all approvers who have approved.
     */
    public function getApprovers(): array
    {
        return $this->actions()
            ->where('status', ApprovalAction::STATUS_APPROVED)
            ->with('actionBy')
            ->get()
            ->map(fn($action) => [
                'user_id' => $action->action_by,
                'user_name' => $action->actionBy?->name,
                'approved_at' => $action->action_at,
                'comments' => $action->comments,
            ])
            ->toArray();
    }

    /**
     * Check if user can approve.
     */
    public function canBeApprovedBy(int $userId): bool
    {
        return $this->actions()
            ->where('workflow_step_id', $this->current_step_id)
            ->where('status', ApprovalAction::STATUS_PENDING)
            ->where(function ($q) use ($userId) {
                $q->where('assigned_to', $userId)
                    ->orWhere('delegated_to', $userId);
            })
            ->exists();
    }

    /**
     * Get approval progress.
     */
    public function getProgress(): array
    {
        $totalSteps = $this->workflow->steps()->count();
        $currentSequence = $this->currentStep?->sequence ?? 0;

        return [
            'total_steps' => $totalSteps,
            'current_step' => $currentSequence + 1,
            'percentage' => $totalSteps > 0 ? round(($currentSequence / $totalSteps) * 100) : 0,
            'status' => $this->status,
        ];
    }
}
