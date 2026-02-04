<?php

declare(strict_types=1);

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeSalary extends Model
{
    protected $fillable = [
        'employee_id',
        'salary_structure_id',
        'effective_from',
        'effective_to',
        'ctc',
        'gross_salary',
        'net_salary',
        'currency_code',
        'reason_for_change',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'ctc' => 'decimal:4',
            'gross_salary' => 'decimal:4',
            'net_salary' => 'decimal:4',
            'is_current' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(EmployeeSalaryComponent::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function getComponentAmount(string $componentCode): float
    {
        $component = $this->components()
            ->whereHas('salaryComponent', fn($q) => $q->where('code', $componentCode))
            ->first();

        return $component ? (float) $component->amount : 0;
    }

    public function getBasicSalary(): float
    {
        return $this->getComponentAmount('BASIC');
    }

    public function getEarnings(): \Illuminate\Support\Collection
    {
        return $this->components()
            ->with('salaryComponent')
            ->whereHas('salaryComponent', fn($q) => $q->where('type', 'earning'))
            ->get();
    }

    public function getDeductions(): \Illuminate\Support\Collection
    {
        return $this->components()
            ->with('salaryComponent')
            ->whereHas('salaryComponent', fn($q) => $q->where('type', 'deduction'))
            ->get();
    }

    public function calculateGrossFromComponents(): float
    {
        return (float) $this->getEarnings()->sum('amount');
    }

    public function calculateNetFromComponents(): float
    {
        $gross = $this->calculateGrossFromComponents();
        $deductions = (float) $this->getDeductions()->sum('amount');

        return max(0, $gross - $deductions);
    }

    public function isActive(): bool
    {
        return $this->is_current &&
            $this->effective_from <= now() &&
            ($this->effective_to === null || $this->effective_to >= now());
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeEffectiveOn($query, $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            });
    }
}
