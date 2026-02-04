<?php

declare(strict_types=1);

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRepayment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'employee_loan_id',
        'payslip_id',
        'installment_number',
        'due_date',
        'principal_amount',
        'interest_amount',
        'total_amount',
        'amount_paid',
        'paid_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'paid_date' => 'date',
            'installment_number' => 'integer',
            'principal_amount' => 'decimal:4',
            'interest_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'amount_paid' => 'decimal:4',
        ];
    }

    public function employeeLoan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->due_date->isPast();
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getBalanceDue(): float
    {
        return max(0, $this->total_amount - $this->amount_paid);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('due_date', '<', now());
    }

    public function scopeDueThisMonth($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereBetween('due_date', [now()->startOfMonth(), now()->endOfMonth()]);
    }
}
