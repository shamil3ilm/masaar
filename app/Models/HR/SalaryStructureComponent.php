<?php

declare(strict_types=1);

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructureComponent extends Model
{
    protected $fillable = [
        'salary_structure_id',
        'salary_component_id',
        'calculation_type',
        'value',
        'percentage_of',
        'formula',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
        ];
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class);
    }

    public function salaryComponent(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class);
    }

    public function calculateAmount(array $context = []): float
    {
        $calcType = $this->calculation_type ?? $this->salaryComponent->calculation_type;
        $value = $this->value ?? $this->salaryComponent->default_value;

        return match ($calcType) {
            SalaryComponent::CALC_FIXED => (float) $value,
            SalaryComponent::CALC_PERCENTAGE => $this->calculatePercentage($value, $context),
            SalaryComponent::CALC_FORMULA => $this->evaluateFormula($context),
            default => 0,
        };
    }

    protected function calculatePercentage(float $value, array $context): float
    {
        $percentageOf = $this->percentage_of ?? $this->salaryComponent->percentage_of;

        if (!$percentageOf) {
            return 0;
        }

        $baseAmount = $context[$percentageOf] ?? 0;
        return round($baseAmount * ($value / 100), 4);
    }

    protected function evaluateFormula(array $context): float
    {
        $formula = $this->formula ?? $this->salaryComponent->formula;

        if (!$formula) {
            return 0;
        }

        foreach ($context as $key => $val) {
            $formula = str_replace('{' . $key . '}', (string) $val, $formula);
        }

        try {
            if (preg_match('/^[\d\s\+\-\*\/\(\)\.]+$/', $formula)) {
                return (float) eval("return {$formula};");
            }
        } catch (\Throwable) {
            return 0;
        }

        return 0;
    }
}
