<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryStructure extends Model
{
    use BelongsToOrganization;

    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_BI_WEEKLY = 'bi_weekly';
    public const FREQUENCY_WEEKLY = 'weekly';

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'currency_code',
        'payroll_frequency',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function components(): HasMany
    {
        return $this->hasMany(SalaryStructureComponent::class);
    }

    public function employeeSalaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class);
    }

    public function getEarningComponents(): \Illuminate\Support\Collection
    {
        return $this->components()
            ->with('salaryComponent')
            ->whereHas('salaryComponent', fn($q) => $q->where('type', 'earning'))
            ->get();
    }

    public function getDeductionComponents(): \Illuminate\Support\Collection
    {
        return $this->components()
            ->with('salaryComponent')
            ->whereHas('salaryComponent', fn($q) => $q->where('type', 'deduction'))
            ->get();
    }

    public function calculateGrossSalary(float $basicSalary): float
    {
        $gross = $basicSalary;

        foreach ($this->getEarningComponents() as $structureComponent) {
            if ($structureComponent->salaryComponent->code === 'BASIC') {
                continue;
            }

            $gross = bcadd(
                (string) $gross,
                (string) $structureComponent->calculateAmount(['basic' => $basicSalary]),
                4
            );
        }

        return (float) $gross;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
