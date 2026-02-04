<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use BelongsToOrganization;

    public const ACCRUAL_ANNUAL = 'annual';
    public const ACCRUAL_MONTHLY = 'monthly';
    public const ACCRUAL_QUARTERLY = 'quarterly';

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'annual_quota',
        'is_paid',
        'is_encashable',
        'max_encashable_days',
        'carry_forward',
        'max_carry_forward_days',
        'min_days_notice',
        'max_consecutive_days',
        'requires_attachment',
        'attachment_required_after_days',
        'half_day_allowed',
        'requires_approval',
        'applicable_gender',
        'applicable_marital_status',
        'applicable_after_months',
        'accrual_type',
        'prorate_on_joining',
        'prorate_on_exit',
        'color',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'annual_quota' => 'decimal:2',
            'max_encashable_days' => 'decimal:2',
            'max_carry_forward_days' => 'decimal:2',
            'max_consecutive_days' => 'decimal:2',
            'is_paid' => 'boolean',
            'is_encashable' => 'boolean',
            'carry_forward' => 'boolean',
            'requires_attachment' => 'boolean',
            'half_day_allowed' => 'boolean',
            'requires_approval' => 'boolean',
            'prorate_on_joining' => 'boolean',
            'prorate_on_exit' => 'boolean',
            'min_days_notice' => 'integer',
            'attachment_required_after_days' => 'integer',
            'applicable_after_months' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function isApplicableToEmployee(Employee $employee): bool
    {
        // Check gender
        if ($this->applicable_gender !== 'all' && $employee->gender !== $this->applicable_gender) {
            return false;
        }

        // Check marital status
        if ($this->applicable_marital_status !== 'all' && $employee->marital_status !== $this->applicable_marital_status) {
            return false;
        }

        // Check tenure
        if ($this->applicable_after_months > 0 && $employee->getTenureInMonths() < $this->applicable_after_months) {
            return false;
        }

        return true;
    }

    public function requiresAttachmentForDays(float $days): bool
    {
        if (!$this->requires_attachment) {
            return false;
        }

        if ($this->attachment_required_after_days <= 0) {
            return true;
        }

        return $days > $this->attachment_required_after_days;
    }

    public function getMonthlyAccrual(): float
    {
        return match ($this->accrual_type) {
            self::ACCRUAL_MONTHLY => (float) $this->annual_quota / 12,
            self::ACCRUAL_QUARTERLY => (float) $this->annual_quota / 4,
            default => (float) $this->annual_quota, // Annual - credited at once
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePaid($query)
    {
        return $query->where('is_paid', true);
    }

    public function scopeEncashable($query)
    {
        return $query->where('is_encashable', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
