<?php

declare(strict_types=1);

namespace App\Models\Manufacturing;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderOperation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'work_order_id',
        'bom_operation_id',
        'name',
        'instructions',
        'sequence',
        'estimated_minutes',
        'actual_minutes',
        'started_at',
        'completed_at',
        'status',
        'assigned_to',
        'completed_by',
        'notes',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'estimated_minutes' => 'integer',
        'actual_minutes' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function bomOperation(): BelongsTo
    {
        return $this->belongsTo(BomOperation::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
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

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeSkipped($query)
    {
        return $query->where('status', self::STATUS_SKIPPED);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sequence');
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
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

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    public function canBeStarted(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canBeCompleted(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * Get estimated time in hours.
     */
    public function getEstimatedHours(): float
    {
        return round($this->estimated_minutes / 60, 2);
    }

    /**
     * Get actual time in hours.
     */
    public function getActualHours(): float
    {
        return round($this->actual_minutes / 60, 2);
    }

    /**
     * Get time variance in minutes.
     */
    public function getTimeVariance(): int
    {
        return $this->actual_minutes - $this->estimated_minutes;
    }

    /**
     * Get time variance percentage.
     */
    public function getTimeVariancePercentage(): float
    {
        if ($this->estimated_minutes === 0) {
            return 0;
        }

        return round((($this->actual_minutes - $this->estimated_minutes) / $this->estimated_minutes) * 100, 2);
    }

    /**
     * Get duration if in progress.
     */
    public function getCurrentDuration(): int
    {
        if (!$this->isInProgress() || !$this->started_at) {
            return 0;
        }

        return now()->diffInMinutes($this->started_at);
    }

    /**
     * Start the operation.
     */
    public function start(?int $userId = null): void
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'assigned_to' => $userId ?? $this->assigned_to ?? auth()->id(),
        ]);
    }

    /**
     * Complete the operation.
     */
    public function complete(?int $actualMinutes = null, ?string $notes = null): void
    {
        $completedAt = now();
        $calculatedMinutes = $actualMinutes ?? $this->started_at?->diffInMinutes($completedAt) ?? 0;

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => $completedAt,
            'actual_minutes' => $calculatedMinutes,
            'completed_by' => auth()->id(),
            'notes' => $notes ?? $this->notes,
        ]);
    }

    /**
     * Skip the operation.
     */
    public function skip(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'notes' => $reason ?? $this->notes,
        ]);
    }

    /**
     * Reset the operation.
     */
    public function reset(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
            'started_at' => null,
            'completed_at' => null,
            'actual_minutes' => 0,
            'completed_by' => null,
        ]);
    }
}
