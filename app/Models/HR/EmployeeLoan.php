<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeLoan extends Model
{
    use BelongsToOrganization, HasUuid;

    public const TYPE_LOAN = 'loan';
    public const TYPE_ADVANCE = 'advance';
    public const TYPE_SALARY_ADVANCE = 'salary_advance';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'loan_number',
        'loan_type',
        'principal_amount',
        'interest_rate',
        'disbursement_date',
        'repayment_start_date',
        'tenure_months',
        'emi_amount',
        'total_repaid',
        'balance',
        'status',
        'approved_by',
        'approved_at',
        'currency_code',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:4',
            'interest_rate' => 'decimal:4',
            'emi_amount' => 'decimal:4',
            'total_repaid' => 'decimal:4',
            'balance' => 'decimal:4',
            'disbursement_date' => 'date',
            'repayment_start_date' => 'date',
            'tenure_months' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class)->orderBy('installment_number');
    }

    public function pendingRepayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class)
            ->where('status', 'pending')
            ->orderBy('due_date');
    }

    public function calculateEmi(): float
    {
        if ($this->interest_rate <= 0) {
            // Simple division for interest-free loans
            return round($this->principal_amount / $this->tenure_months, 4);
        }

        // EMI = [P x R x (1+R)^N] / [(1+R)^N - 1]
        $p = $this->principal_amount;
        $r = $this->interest_rate / 12 / 100; // Monthly interest rate
        $n = $this->tenure_months;

        $emi = ($p * $r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1);

        return round($emi, 4);
    }

    public function getTotalInterest(): float
    {
        return ($this->emi_amount * $this->tenure_months) - $this->principal_amount;
    }

    public function getCompletionPercentage(): float
    {
        if ($this->principal_amount <= 0) {
            return 100;
        }

        return round(($this->total_repaid / $this->principal_amount) * 100, 2);
    }

    public function getNextRepayment(): ?LoanRepayment
    {
        return $this->repayments()
            ->where('status', 'pending')
            ->orderBy('due_date')
            ->first();
    }

    public function recordRepayment(float $amount, ?int $payslipId = null): void
    {
        $this->total_repaid = bcadd((string) $this->total_repaid, (string) $amount, 4);
        $this->balance = bcsub((string) $this->balance, (string) $amount, 4);

        if ($this->balance <= 0) {
            $this->status = self::STATUS_COMPLETED;
            $this->balance = 0;
        }

        $this->save();

        // Update next pending repayment
        $nextRepayment = $this->getNextRepayment();
        if ($nextRepayment) {
            $nextRepayment->amount_paid = $amount;
            $nextRepayment->paid_date = now();
            $nextRepayment->payslip_id = $payslipId;
            $nextRepayment->status = 'paid';
            $nextRepayment->save();
        }
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }
}
