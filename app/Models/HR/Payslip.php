<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Accounting\JournalEntry;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasStateMachine;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payslip extends Model
{
    use BelongsToOrganization, HasUuid, HasStateMachine, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'payroll_period_id',
        'employee_id',
        'employee_salary_id',
        'payslip_number',
        'payment_date',
        'total_working_days',
        'days_worked',
        'days_on_leave',
        'unpaid_leave_days',
        'overtime_hours',
        'gross_earnings',
        'total_deductions',
        'net_salary',
        'currency_code',
        'taxable_income',
        'tax_deducted',
        'status',
        'approved_by',
        'approved_at',
        'payment_mode',
        'payment_reference',
        'paid_at',
        'journal_entry_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'total_working_days' => 'decimal:2',
            'days_worked' => 'decimal:2',
            'days_on_leave' => 'decimal:2',
            'unpaid_leave_days' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'gross_earnings' => 'decimal:4',
            'total_deductions' => 'decimal:4',
            'net_salary' => 'decimal:4',
            'taxable_income' => 'decimal:4',
            'tax_deducted' => 'decimal:4',
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
            self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_CANCELLED],
            self::STATUS_APPROVED => [self::STATUS_PAID, self::STATUS_CANCELLED],
            self::STATUS_PAID => [],
            self::STATUS_CANCELLED => [],
        ];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employeeSalary(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalary::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayslipItem::class)->orderBy('type')->orderBy('sort_order');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(PayslipItem::class)
            ->where('type', 'earning')
            ->orderBy('sort_order');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(PayslipItem::class)
            ->where('type', 'deduction')
            ->orderBy('sort_order');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recalculateTotals(): void
    {
        $this->gross_earnings = $this->earnings()->sum('amount');
        $this->total_deductions = $this->deductions()->sum('amount');
        $this->net_salary = max(0, $this->gross_earnings - $this->total_deductions);
        $this->save();
    }

    public function getPayDayRate(): float
    {
        if ($this->total_working_days <= 0) {
            return 0;
        }

        return round($this->gross_earnings / $this->total_working_days, 4);
    }

    public function getLossOfPayDeduction(): float
    {
        return round($this->getPayDayRate() * $this->unpaid_leave_days, 4);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING], true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeForPeriod($query, int $periodId)
    {
        return $query->where('payroll_period_id', $periodId);
    }
}
