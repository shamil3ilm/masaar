<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'leave_type_id',
        'year',
        'opening_balance',
        'accrued',
        'taken',
        'adjustment',
        'encashed',
        'lapsed',
        'closing_balance',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'opening_balance' => 'decimal:2',
            'accrued' => 'decimal:2',
            'taken' => 'decimal:2',
            'adjustment' => 'decimal:2',
            'encashed' => 'decimal:2',
            'lapsed' => 'decimal:2',
            'closing_balance' => 'decimal:2',
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

    public function recalculateClosingBalance(): void
    {
        $this->closing_balance = bcadd(
            bcadd(
                bcadd((string) $this->opening_balance, (string) $this->accrued, 2),
                (string) $this->adjustment,
                2
            ),
            bcsub(
                '0',
                bcadd(
                    bcadd((string) $this->taken, (string) $this->encashed, 2),
                    (string) $this->lapsed,
                    2
                ),
                2
            ),
            2
        );
    }

    public function getAvailableBalance(): float
    {
        return max(0, (float) $this->closing_balance);
    }

    public function hasBalance(float $days): bool
    {
        return $this->getAvailableBalance() >= $days;
    }

    public function deductLeave(float $days): void
    {
        $this->taken = bcadd((string) $this->taken, (string) $days, 2);
        $this->recalculateClosingBalance();
        $this->save();
    }

    public function creditLeave(float $days): void
    {
        $this->accrued = bcadd((string) $this->accrued, (string) $days, 2);
        $this->recalculateClosingBalance();
        $this->save();
    }

    public function adjustBalance(float $days, string $reason = ''): void
    {
        $this->adjustment = bcadd((string) $this->adjustment, (string) $days, 2);
        if ($reason) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . now()->format('Y-m-d') . ": {$reason}";
        }
        $this->recalculateClosingBalance();
        $this->save();
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    public function scopeForLeaveType($query, int $leaveTypeId)
    {
        return $query->where('leave_type_id', $leaveTypeId);
    }

    public function scopeWithBalance($query)
    {
        return $query->where('closing_balance', '>', 0);
    }
}
