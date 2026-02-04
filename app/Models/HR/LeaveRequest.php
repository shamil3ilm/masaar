<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasStateMachine;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveRequest extends Model
{
    use BelongsToOrganization, HasUuid, HasStateMachine, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const HALF_DAY_FIRST = 'first_half';
    public const HALF_DAY_SECOND = 'second_half';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'leave_type_id',
        'from_date',
        'to_date',
        'total_days',
        'is_half_day',
        'half_day_type',
        'reason',
        'contact_during_leave',
        'address_during_leave',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'cancelled_at',
        'cancellation_reason',
        'attachment_path',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'total_days' => 'decimal:2',
            'is_half_day' => 'boolean',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected function getStateField(): string
    {
        return 'status';
    }

    protected function getStateTransitions(): array
    {
        return [
            self::STATUS_DRAFT => [self::STATUS_PENDING, self::STATUS_CANCELLED],
            self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
            self::STATUS_APPROVED => [self::STATUS_CANCELLED],
            self::STATUS_REJECTED => [self::STATUS_PENDING], // Can resubmit
            self::STATUS_CANCELLED => [],
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function calculateTotalDays(): float
    {
        if ($this->is_half_day) {
            return 0.5;
        }

        // Simple calculation - excludes weekends/holidays
        // In a real system, this would be more complex
        return $this->from_date->diffInDays($this->to_date) + 1;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

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

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeCancelled(): bool
    {
        // Can cancel if not yet started or status allows
        if ($this->status === self::STATUS_CANCELLED) {
            return false;
        }

        if ($this->from_date->isPast() && $this->status === self::STATUS_APPROVED) {
            return false; // Already started
        }

        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED], true);
    }

    public function overlapsWithExisting(): bool
    {
        return self::where('employee_id', $this->employee_id)
            ->where('id', '!=', $this->id)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED])
            ->where(function ($query) {
                $query->whereBetween('from_date', [$this->from_date, $this->to_date])
                    ->orWhereBetween('to_date', [$this->from_date, $this->to_date])
                    ->orWhere(function ($q) {
                        $q->where('from_date', '<=', $this->from_date)
                            ->where('to_date', '>=', $this->to_date);
                    });
            })
            ->exists();
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('from_date', [$startDate, $endDate])
                ->orWhereBetween('to_date', [$startDate, $endDate]);
        });
    }

    public function scopeUpcoming($query)
    {
        return $query->where('from_date', '>', now())
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }

    public function scopeOngoing($query)
    {
        return $query->where('from_date', '<=', now())
            ->where('to_date', '>=', now())
            ->where('status', self::STATUS_APPROVED);
    }
}
