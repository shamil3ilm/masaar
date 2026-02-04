<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Models\Core\Organization;
use App\Models\HR\Employee;
use App\Models\HR\Payslip;
use Illuminate\Support\Collection;

class StatutoryDeductionService
{
    /**
     * Statutory deduction configurations by country.
     */
    protected const CONFIGURATIONS = [
        // Saudi Arabia
        'SA' => [
            'gosi' => [
                'name' => 'GOSI (General Organization for Social Insurance)',
                'code' => 'GOSI',
                'employee_rate' => 10.00, // 10% employee share
                'employer_rate' => 12.00, // 12% employer share (includes 2% SANED)
                'max_salary' => 45000, // SAR ceiling
                'applies_to' => ['citizen', 'gcc_national'], // Non-GCC exempt
            ],
            'saned' => [
                'name' => 'SANED (Unemployment Insurance)',
                'code' => 'SANED',
                'employee_rate' => 0.75,
                'employer_rate' => 0.75,
                'max_salary' => 45000,
                'applies_to' => ['citizen'],
            ],
        ],

        // United Arab Emirates
        'AE' => [
            'pension' => [
                'name' => 'UAE Pension Fund',
                'code' => 'UAE_PENSION',
                'employee_rate' => 5.00, // Citizens only
                'employer_rate' => 15.00, // Citizens: 12.5% + 2.5% government
                'applies_to' => ['citizen'],
                'by_emirate' => [
                    'AUH' => ['employer_rate' => 15.00],
                    'DXB' => ['employer_rate' => 15.00],
                    'SHJ' => ['employer_rate' => 15.00],
                ],
            ],
            'gpssa' => [
                'name' => 'Abu Dhabi Retirement Pensions & Benefits Fund',
                'code' => 'GPSSA',
                'employee_rate' => 5.00,
                'employer_rate' => 15.00,
                'applies_to' => ['citizen'],
                'emirate' => 'AUH',
            ],
        ],

        // Oman
        'OM' => [
            'pasi' => [
                'name' => 'Public Authority for Social Insurance',
                'code' => 'PASI',
                'employee_rate' => 7.00, // Citizens only
                'employer_rate' => 11.50, // Citizens only
                'applies_to' => ['citizen'],
            ],
        ],

        // Bahrain
        'BH' => [
            'sio' => [
                'name' => 'Social Insurance Organization',
                'code' => 'SIO',
                'employee_rate_citizen' => 8.00,
                'employer_rate_citizen' => 12.00,
                'employee_rate_expat' => 1.00, // Occupational hazard only
                'employer_rate_expat' => 3.00,
                'unemployment_employee' => 1.00,
                'unemployment_employer' => 1.00,
                'max_salary' => 4000, // BHD ceiling for basic
            ],
        ],

        // Kuwait
        'KW' => [
            'pifss' => [
                'name' => 'Public Institution for Social Security',
                'code' => 'PIFSS',
                'employee_rate' => 10.50,
                'employer_rate' => 11.50,
                'applies_to' => ['citizen'],
                'max_salary' => 2750, // KWD ceiling
            ],
        ],

        // Qatar
        'QA' => [
            'grsia' => [
                'name' => 'General Retirement & Social Insurance Authority',
                'code' => 'GRSIA',
                'employee_rate' => 5.00,
                'employer_rate' => 10.00,
                'applies_to' => ['citizen'],
            ],
        ],

        // India
        'IN' => [
            'pf' => [
                'name' => 'Employees Provident Fund',
                'code' => 'EPF',
                'employee_rate' => 12.00,
                'employer_rate' => 12.00, // Includes 3.67% EPF + 8.33% EPS
                'max_salary' => 15000, // INR ceiling for mandatory
                'eps_rate' => 8.33, // Employer's EPS contribution
                'epf_rate' => 3.67, // Employer's EPF contribution
            ],
            'esi' => [
                'name' => 'Employee State Insurance',
                'code' => 'ESI',
                'employee_rate' => 0.75,
                'employer_rate' => 3.25,
                'max_salary' => 21000, // INR eligibility ceiling
            ],
            'professional_tax' => [
                'name' => 'Professional Tax',
                'code' => 'PT',
                'type' => 'state_based',
                'max_monthly' => 2500, // INR max per month
            ],
            'tds' => [
                'name' => 'Tax Deducted at Source',
                'code' => 'TDS',
                'type' => 'income_tax',
            ],
            'lwf' => [
                'name' => 'Labour Welfare Fund',
                'code' => 'LWF',
                'employee_amount' => 20, // INR fixed
                'employer_amount' => 40, // INR fixed
                'frequency' => 'half_yearly',
            ],
        ],
    ];

    /**
     * India Professional Tax slabs by state.
     */
    protected const INDIA_PT_SLABS = [
        'MH' => [ // Maharashtra
            ['min' => 0, 'max' => 7500, 'tax' => 0],
            ['min' => 7501, 'max' => 10000, 'tax' => 175],
            ['min' => 10001, 'max' => INF, 'tax' => 200], // 2500 max per year
        ],
        'KA' => [ // Karnataka
            ['min' => 0, 'max' => 15000, 'tax' => 0],
            ['min' => 15001, 'max' => INF, 'tax' => 200],
        ],
        'TN' => [ // Tamil Nadu
            ['min' => 0, 'max' => 21000, 'tax' => 0],
            ['min' => 21001, 'max' => 30000, 'tax' => 135],
            ['min' => 30001, 'max' => 45000, 'tax' => 315],
            ['min' => 45001, 'max' => 60000, 'tax' => 690],
            ['min' => 60001, 'max' => 75000, 'tax' => 1025],
            ['min' => 75001, 'max' => INF, 'tax' => 1250], // Half yearly
        ],
        'GJ' => [ // Gujarat
            ['min' => 0, 'max' => 5999, 'tax' => 0],
            ['min' => 6000, 'max' => 8999, 'tax' => 80],
            ['min' => 9000, 'max' => 11999, 'tax' => 150],
            ['min' => 12000, 'max' => INF, 'tax' => 200],
        ],
        'WB' => [ // West Bengal
            ['min' => 0, 'max' => 10000, 'tax' => 0],
            ['min' => 10001, 'max' => 15000, 'tax' => 110],
            ['min' => 15001, 'max' => 25000, 'tax' => 130],
            ['min' => 25001, 'max' => 40000, 'tax' => 150],
            ['min' => 40001, 'max' => INF, 'tax' => 200],
        ],
        'AP' => [ // Andhra Pradesh
            ['min' => 0, 'max' => 15000, 'tax' => 0],
            ['min' => 15001, 'max' => 20000, 'tax' => 150],
            ['min' => 20001, 'max' => INF, 'tax' => 200],
        ],
        'TS' => [ // Telangana
            ['min' => 0, 'max' => 15000, 'tax' => 0],
            ['min' => 15001, 'max' => 20000, 'tax' => 150],
            ['min' => 20001, 'max' => INF, 'tax' => 200],
        ],
        'KL' => [ // Kerala
            ['min' => 0, 'max' => 11999, 'tax' => 0],
            ['min' => 12000, 'max' => 17999, 'tax' => 120],
            ['min' => 18000, 'max' => 29999, 'tax' => 180],
            ['min' => 30000, 'max' => INF, 'tax' => 250], // Per half year
        ],
    ];

    /**
     * India Income Tax slabs (New Regime FY 2024-25).
     */
    protected const INDIA_TAX_SLABS_NEW = [
        ['min' => 0, 'max' => 300000, 'rate' => 0],
        ['min' => 300001, 'max' => 700000, 'rate' => 5],
        ['min' => 700001, 'max' => 1000000, 'rate' => 10],
        ['min' => 1000001, 'max' => 1200000, 'rate' => 15],
        ['min' => 1200001, 'max' => 1500000, 'rate' => 20],
        ['min' => 1500001, 'max' => INF, 'rate' => 30],
    ];

    /**
     * India Income Tax slabs (Old Regime FY 2024-25).
     */
    protected const INDIA_TAX_SLABS_OLD = [
        ['min' => 0, 'max' => 250000, 'rate' => 0],
        ['min' => 250001, 'max' => 500000, 'rate' => 5],
        ['min' => 500001, 'max' => 1000000, 'rate' => 20],
        ['min' => 1000001, 'max' => INF, 'rate' => 30],
    ];

    /**
     * Calculate all statutory deductions for an employee.
     */
    public function calculateDeductions(
        Employee $employee,
        float $grossSalary,
        ?string $countryCode = null
    ): array {
        $country = $countryCode ?? $employee->organization->country_code;
        $deductions = [];
        $employerContributions = [];

        $config = self::CONFIGURATIONS[$country] ?? null;

        if (!$config) {
            return [
                'employee_deductions' => [],
                'employer_contributions' => [],
                'total_employee' => 0,
                'total_employer' => 0,
            ];
        }

        // GCC Countries
        if (in_array($country, ['SA', 'AE', 'OM', 'BH', 'KW', 'QA'])) {
            $result = $this->calculateGccDeductions($employee, $grossSalary, $country, $config);
            $deductions = array_merge($deductions, $result['employee']);
            $employerContributions = array_merge($employerContributions, $result['employer']);
        }

        // India
        if ($country === 'IN') {
            $result = $this->calculateIndiaDeductions($employee, $grossSalary, $config);
            $deductions = array_merge($deductions, $result['employee']);
            $employerContributions = array_merge($employerContributions, $result['employer']);
        }

        return [
            'employee_deductions' => $deductions,
            'employer_contributions' => $employerContributions,
            'total_employee' => collect($deductions)->sum('amount'),
            'total_employer' => collect($employerContributions)->sum('amount'),
        ];
    }

    /**
     * Calculate GCC statutory deductions.
     */
    protected function calculateGccDeductions(
        Employee $employee,
        float $grossSalary,
        string $countryCode,
        array $config
    ): array {
        $deductions = [];
        $employerContributions = [];

        $nationalityType = $this->getNationalityType($employee, $countryCode);

        foreach ($config as $code => $scheme) {
            // Check if scheme applies to this employee
            if (isset($scheme['applies_to'])) {
                if (!in_array($nationalityType, $scheme['applies_to'])) {
                    continue;
                }
            }

            // Calculate applicable salary (with ceiling)
            $applicableSalary = isset($scheme['max_salary'])
                ? min($grossSalary, $scheme['max_salary'])
                : $grossSalary;

            // Employee deduction
            $employeeRate = $scheme['employee_rate'] ?? 0;
            if ($employeeRate > 0) {
                $amount = round($applicableSalary * ($employeeRate / 100), 2);
                $deductions[] = [
                    'code' => $scheme['code'],
                    'name' => $scheme['name'],
                    'amount' => $amount,
                    'rate' => $employeeRate,
                    'base_salary' => $applicableSalary,
                    'is_statutory' => true,
                ];
            }

            // Employer contribution
            $employerRate = $scheme['employer_rate'] ?? 0;
            if ($employerRate > 0) {
                $amount = round($applicableSalary * ($employerRate / 100), 2);
                $employerContributions[] = [
                    'code' => $scheme['code'],
                    'name' => $scheme['name'] . ' (Employer)',
                    'amount' => $amount,
                    'rate' => $employerRate,
                    'base_salary' => $applicableSalary,
                    'is_statutory' => true,
                ];
            }
        }

        return ['employee' => $deductions, 'employer' => $employerContributions];
    }

    /**
     * Calculate India statutory deductions.
     */
    protected function calculateIndiaDeductions(
        Employee $employee,
        float $grossSalary,
        array $config
    ): array {
        $deductions = [];
        $employerContributions = [];

        // EPF Calculation
        if (isset($config['pf'])) {
            $pf = $config['pf'];
            $pfSalary = min($grossSalary, $pf['max_salary']);

            // Employee PF (12%)
            $employeePf = round($pfSalary * ($pf['employee_rate'] / 100), 2);
            $deductions[] = [
                'code' => 'EPF_EE',
                'name' => 'Employee Provident Fund',
                'amount' => $employeePf,
                'rate' => $pf['employee_rate'],
                'base_salary' => $pfSalary,
                'is_statutory' => true,
            ];

            // Employer EPF (3.67%)
            $employerEpf = round($pfSalary * ($pf['epf_rate'] / 100), 2);
            $employerContributions[] = [
                'code' => 'EPF_ER',
                'name' => 'Employer PF Contribution',
                'amount' => $employerEpf,
                'rate' => $pf['epf_rate'],
                'base_salary' => $pfSalary,
                'is_statutory' => true,
            ];

            // Employer EPS (8.33%)
            $employerEps = round($pfSalary * ($pf['eps_rate'] / 100), 2);
            $employerContributions[] = [
                'code' => 'EPS_ER',
                'name' => 'Employer Pension Contribution',
                'amount' => $employerEps,
                'rate' => $pf['eps_rate'],
                'base_salary' => $pfSalary,
                'is_statutory' => true,
            ];
        }

        // ESI Calculation (only if salary <= 21000)
        if (isset($config['esi']) && $grossSalary <= $config['esi']['max_salary']) {
            $esi = $config['esi'];

            // Employee ESI (0.75%)
            $employeeEsi = round($grossSalary * ($esi['employee_rate'] / 100), 2);
            $deductions[] = [
                'code' => 'ESI_EE',
                'name' => 'Employee State Insurance',
                'amount' => $employeeEsi,
                'rate' => $esi['employee_rate'],
                'base_salary' => $grossSalary,
                'is_statutory' => true,
            ];

            // Employer ESI (3.25%)
            $employerEsi = round($grossSalary * ($esi['employer_rate'] / 100), 2);
            $employerContributions[] = [
                'code' => 'ESI_ER',
                'name' => 'Employer ESI Contribution',
                'amount' => $employerEsi,
                'rate' => $esi['employer_rate'],
                'base_salary' => $grossSalary,
                'is_statutory' => true,
            ];
        }

        // Professional Tax
        $stateCode = $employee->work_state ?? $employee->branch?->state ?? 'MH';
        $pt = $this->calculateIndiaProfessionalTax($grossSalary, $stateCode);
        if ($pt > 0) {
            $deductions[] = [
                'code' => 'PT',
                'name' => 'Professional Tax',
                'amount' => $pt,
                'state' => $stateCode,
                'is_statutory' => true,
            ];
        }

        // TDS (Income Tax) - Simplified calculation
        $annualSalary = $grossSalary * 12;
        $tds = $this->calculateIndiaTds($annualSalary, $employee->tax_regime ?? 'new');
        if ($tds > 0) {
            $monthlyTds = round($tds / 12, 2);
            $deductions[] = [
                'code' => 'TDS',
                'name' => 'Tax Deducted at Source',
                'amount' => $monthlyTds,
                'annual_tax' => $tds,
                'regime' => $employee->tax_regime ?? 'new',
                'is_statutory' => true,
            ];
        }

        return ['employee' => $deductions, 'employer' => $employerContributions];
    }

    /**
     * Calculate India Professional Tax based on state.
     */
    public function calculateIndiaProfessionalTax(float $grossSalary, string $stateCode): float
    {
        $slabs = self::INDIA_PT_SLABS[$stateCode] ?? self::INDIA_PT_SLABS['MH'];

        foreach ($slabs as $slab) {
            if ($grossSalary >= $slab['min'] && $grossSalary <= $slab['max']) {
                return (float) $slab['tax'];
            }
        }

        return 0;
    }

    /**
     * Calculate India TDS (simplified, without deductions).
     */
    public function calculateIndiaTds(float $annualSalary, string $regime = 'new'): float
    {
        $slabs = $regime === 'new'
            ? self::INDIA_TAX_SLABS_NEW
            : self::INDIA_TAX_SLABS_OLD;

        // Standard deduction
        $standardDeduction = $regime === 'new' ? 75000 : 50000;
        $taxableIncome = max(0, $annualSalary - $standardDeduction);

        $tax = 0;
        $previousMax = 0;

        foreach ($slabs as $slab) {
            if ($taxableIncome <= 0) {
                break;
            }

            $slabMin = $slab['min'];
            $slabMax = $slab['max'] === INF ? $taxableIncome + $slabMin : $slab['max'];
            $slabAmount = min($taxableIncome, $slabMax - $slabMin + 1);

            if ($taxableIncome > $slabMin) {
                $taxableInSlab = min($taxableIncome - $slabMin + 1, $slabMax - $slabMin + 1);
                $tax += $taxableInSlab * ($slab['rate'] / 100);
            }

            $previousMax = $slabMax;
        }

        // Rebate under 87A for new regime (if taxable income <= 7 lakh)
        if ($regime === 'new' && $taxableIncome <= 700000 && $tax > 0) {
            $tax = max(0, $tax - 25000);
        }

        // Add 4% Health & Education Cess
        $tax = $tax * 1.04;

        return round($tax, 2);
    }

    /**
     * Determine nationality type for GCC countries.
     */
    protected function getNationalityType(Employee $employee, string $countryCode): string
    {
        $nationality = strtoupper($employee->nationality ?? '');
        $gccCountries = ['SA', 'AE', 'OM', 'BH', 'KW', 'QA'];

        if ($nationality === $countryCode) {
            return 'citizen';
        }

        if (in_array($nationality, $gccCountries)) {
            return 'gcc_national';
        }

        return 'expat';
    }

    /**
     * Get statutory configuration for a country.
     */
    public function getConfiguration(string $countryCode): array
    {
        return self::CONFIGURATIONS[$countryCode] ?? [];
    }

    /**
     * Get professional tax slabs for an Indian state.
     */
    public function getIndiaPtSlabs(string $stateCode): array
    {
        return self::INDIA_PT_SLABS[$stateCode] ?? self::INDIA_PT_SLABS['MH'];
    }

    /**
     * Get income tax slabs for India.
     */
    public function getIndiaTaxSlabs(string $regime = 'new'): array
    {
        return $regime === 'new'
            ? self::INDIA_TAX_SLABS_NEW
            : self::INDIA_TAX_SLABS_OLD;
    }

    /**
     * Generate compliance report for statutory deductions.
     */
    public function generateComplianceReport(
        int $organizationId,
        string $periodStart,
        string $periodEnd,
        string $countryCode
    ): array {
        $payslips = Payslip::where('organization_id', $organizationId)
            ->whereHas('payrollPeriod', function ($q) use ($periodStart, $periodEnd) {
                $q->whereBetween('start_date', [$periodStart, $periodEnd]);
            })
            ->whereIn('status', [Payslip::STATUS_APPROVED, Payslip::STATUS_PAID])
            ->with(['items', 'employee'])
            ->get();

        $summary = [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'country' => $countryCode,
            'employee_count' => $payslips->pluck('employee_id')->unique()->count(),
            'payslip_count' => $payslips->count(),
            'deductions' => [],
            'employer_contributions' => [],
        ];

        $config = self::CONFIGURATIONS[$countryCode] ?? [];

        foreach ($config as $code => $scheme) {
            $employeeTotal = $payslips->sum(function ($payslip) use ($scheme) {
                return $payslip->items
                    ->where('type', 'deduction')
                    ->where('salary_component.code', $scheme['code'] ?? '')
                    ->sum('amount');
            });

            if ($employeeTotal > 0 || isset($scheme['employee_rate'])) {
                $summary['deductions'][$code] = [
                    'code' => $scheme['code'] ?? $code,
                    'name' => $scheme['name'] ?? $code,
                    'employee_total' => $employeeTotal,
                    'employer_total' => $employeeTotal * (($scheme['employer_rate'] ?? 0) / ($scheme['employee_rate'] ?? 1)),
                ];
            }
        }

        return $summary;
    }
}
