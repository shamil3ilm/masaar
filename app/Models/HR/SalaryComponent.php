<?php

declare(strict_types=1);

namespace App\Models\HR;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SalaryComponent extends Model
{
    use BelongsToOrganization;

    public const TYPE_EARNING = 'earning';
    public const TYPE_DEDUCTION = 'deduction';

    public const CATEGORY_BASIC = 'basic';
    public const CATEGORY_ALLOWANCE = 'allowance';
    public const CATEGORY_BONUS = 'bonus';
    public const CATEGORY_REIMBURSEMENT = 'reimbursement';
    public const CATEGORY_STATUTORY_DEDUCTION = 'statutory_deduction';
    public const CATEGORY_VOLUNTARY_DEDUCTION = 'voluntary_deduction';
    public const CATEGORY_TAX = 'tax';

    public const CALC_FIXED = 'fixed';
    public const CALC_PERCENTAGE = 'percentage';
    public const CALC_FORMULA = 'formula';

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'type',
        'category',
        'calculation_type',
        'default_value',
        'percentage_of',
        'formula',
        'is_taxable',
        'is_pro_rata',
        'is_statutory',
        'is_flexible_benefit',
        'show_in_payslip',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_value' => 'decimal:4',
            'is_taxable' => 'boolean',
            'is_pro_rata' => 'boolean',
            'is_statutory' => 'boolean',
            'is_flexible_benefit' => 'boolean',
            'show_in_payslip' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function isEarning(): bool
    {
        return $this->type === self::TYPE_EARNING;
    }

    public function isDeduction(): bool
    {
        return $this->type === self::TYPE_DEDUCTION;
    }

    public function calculateAmount(array $context = []): float
    {
        return match ($this->calculation_type) {
            self::CALC_FIXED => (float) $this->default_value,
            self::CALC_PERCENTAGE => $this->calculatePercentage($context),
            self::CALC_FORMULA => $this->evaluateFormula($context),
            default => 0,
        };
    }

    protected function calculatePercentage(array $context): float
    {
        if (!$this->percentage_of) {
            return 0;
        }

        $baseAmount = $context[$this->percentage_of] ?? 0;
        return round($baseAmount * ($this->default_value / 100), 4);
    }

    protected function evaluateFormula(array $context): float
    {
        if (!$this->formula) {
            return 0;
        }

        // Simple formula evaluation - in production use a proper expression parser
        $formula = $this->formula;
        foreach ($context as $key => $value) {
            $formula = str_replace('{' . $key . '}', (string) $value, $formula);
        }

        try {
            // Only for simple math expressions
            if (preg_match('/^[\d\s\+\-\*\/\(\)\.]+$/', $formula)) {
                return (float) eval("return {$formula};");
            }
        } catch (\Throwable) {
            return 0;
        }

        return 0;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEarnings($query)
    {
        return $query->where('type', self::TYPE_EARNING);
    }

    public function scopeDeductions($query)
    {
        return $query->where('type', self::TYPE_DEDUCTION);
    }

    public function scopeStatutory($query)
    {
        return $query->where('is_statutory', true);
    }

    public function scopeTaxable($query)
    {
        return $query->where('is_taxable', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('type')->orderBy('sort_order')->orderBy('name');
    }
}
