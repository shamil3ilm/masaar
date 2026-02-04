<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Models\HR\Employee;
use App\Models\HR\EmployeeSalary;
use App\Models\HR\EmployeeSalaryComponent;
use App\Models\HR\SalaryStructure;
use App\Services\Core\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

class EmployeeService
{
    public function __construct(
        private NumberGeneratorService $numberGenerator
    ) {}

    /**
     * Create a new employee.
     */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            if (empty($data['employee_number'])) {
                $data['employee_number'] = $this->numberGenerator->generate('EMP');
            }

            if (empty($data['display_name'])) {
                $data['display_name'] = trim("{$data['first_name']} {$data['last_name']}");
            }

            $employee = Employee::create($data);

            return $employee;
        });
    }

    /**
     * Update an employee.
     */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            if (isset($data['first_name']) || isset($data['last_name'])) {
                $firstName = $data['first_name'] ?? $employee->first_name;
                $lastName = $data['last_name'] ?? $employee->last_name;

                if (empty($data['display_name'])) {
                    $data['display_name'] = trim("{$firstName} {$lastName}");
                }
            }

            $employee->update($data);

            return $employee->fresh();
        });
    }

    /**
     * Assign salary structure to employee.
     */
    public function assignSalary(
        Employee $employee,
        SalaryStructure $structure,
        array $componentAmounts,
        \DateTimeInterface $effectiveFrom,
        ?string $reason = null
    ): EmployeeSalary {
        return DB::transaction(function () use ($employee, $structure, $componentAmounts, $effectiveFrom, $reason) {
            // Deactivate previous salary
            EmployeeSalary::where('employee_id', $employee->id)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'effective_to' => $effectiveFrom,
                ]);

            // Calculate totals
            $grossSalary = 0;
            $totalDeductions = 0;

            foreach ($structure->components()->with('salaryComponent')->get() as $structureComponent) {
                $component = $structureComponent->salaryComponent;
                $amount = $componentAmounts[$component->code] ?? $structureComponent->value ?? $component->default_value;

                if ($component->isEarning()) {
                    $grossSalary = bcadd((string) $grossSalary, (string) $amount, 4);
                } else {
                    $totalDeductions = bcadd((string) $totalDeductions, (string) $amount, 4);
                }
            }

            $netSalary = bcsub((string) $grossSalary, (string) $totalDeductions, 4);
            $ctc = bcmul((string) $grossSalary, '12', 4); // Annual CTC

            // Create new salary assignment
            $employeeSalary = EmployeeSalary::create([
                'employee_id' => $employee->id,
                'salary_structure_id' => $structure->id,
                'effective_from' => $effectiveFrom,
                'ctc' => $ctc,
                'gross_salary' => $grossSalary,
                'net_salary' => $netSalary,
                'currency_code' => $structure->currency_code,
                'reason_for_change' => $reason,
                'is_current' => true,
            ]);

            // Create component amounts
            foreach ($structure->components()->with('salaryComponent')->get() as $structureComponent) {
                $component = $structureComponent->salaryComponent;
                $amount = $componentAmounts[$component->code] ?? $structureComponent->value ?? $component->default_value;

                EmployeeSalaryComponent::create([
                    'employee_salary_id' => $employeeSalary->id,
                    'salary_component_id' => $component->id,
                    'amount' => $amount,
                ]);
            }

            return $employeeSalary->load('components.salaryComponent');
        });
    }

    /**
     * Terminate an employee.
     */
    public function terminate(Employee $employee, \DateTimeInterface $terminationDate, string $reason, string $status = 'terminated'): Employee
    {
        return DB::transaction(function () use ($employee, $terminationDate, $reason, $status) {
            $employee->update([
                'employment_status' => $status,
                'termination_date' => $terminationDate,
                'termination_reason' => $reason,
                'is_active' => false,
            ]);

            // Deactivate current salary
            EmployeeSalary::where('employee_id', $employee->id)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'effective_to' => $terminationDate,
                ]);

            return $employee->fresh();
        });
    }

    /**
     * Confirm an employee (end probation).
     */
    public function confirm(Employee $employee, ?\DateTimeInterface $confirmationDate = null): Employee
    {
        $employee->update([
            'confirmation_date' => $confirmationDate ?? now(),
            'employment_type' => Employee::EMPLOYMENT_TYPE_FULL_TIME,
        ]);

        return $employee->fresh();
    }

    /**
     * Transfer employee to different department/branch.
     */
    public function transfer(
        Employee $employee,
        ?int $departmentId = null,
        ?int $designationId = null,
        ?int $branchId = null,
        ?int $reportingManagerId = null
    ): Employee {
        $data = array_filter([
            'department_id' => $departmentId,
            'designation_id' => $designationId,
            'branch_id' => $branchId,
            'reporting_manager_id' => $reportingManagerId,
        ], fn($v) => $v !== null);

        $employee->update($data);

        return $employee->fresh();
    }

    /**
     * Get employees with expiring documents.
     */
    public function getExpiringDocuments(int $daysThreshold = 30): \Illuminate\Support\Collection
    {
        $checkDate = now()->addDays($daysThreshold);

        $employees = Employee::active()
            ->where(function ($q) use ($checkDate) {
                $q->whereNotNull('passport_expiry')->where('passport_expiry', '<=', $checkDate)
                    ->orWhere(function ($q2) use ($checkDate) {
                        $q2->whereNotNull('visa_expiry')->where('visa_expiry', '<=', $checkDate);
                    })
                    ->orWhere(function ($q3) use ($checkDate) {
                        $q3->whereNotNull('work_permit_expiry')->where('work_permit_expiry', '<=', $checkDate);
                    });
            })
            ->get();

        $results = collect();

        foreach ($employees as $employee) {
            if ($employee->passport_expiry && $employee->passport_expiry->lte($checkDate)) {
                $results->push([
                    'employee' => $employee,
                    'document_type' => 'passport',
                    'expiry_date' => $employee->passport_expiry,
                    'days_until_expiry' => now()->diffInDays($employee->passport_expiry, false),
                ]);
            }
            if ($employee->visa_expiry && $employee->visa_expiry->lte($checkDate)) {
                $results->push([
                    'employee' => $employee,
                    'document_type' => 'visa',
                    'expiry_date' => $employee->visa_expiry,
                    'days_until_expiry' => now()->diffInDays($employee->visa_expiry, false),
                ]);
            }
            if ($employee->work_permit_expiry && $employee->work_permit_expiry->lte($checkDate)) {
                $results->push([
                    'employee' => $employee,
                    'document_type' => 'work_permit',
                    'expiry_date' => $employee->work_permit_expiry,
                    'days_until_expiry' => now()->diffInDays($employee->work_permit_expiry, false),
                ]);
            }
        }

        return $results->sortBy('days_until_expiry');
    }

    /**
     * Get employee statistics.
     */
    public function getStatistics(): array
    {
        $active = Employee::active()->count();
        $onNotice = Employee::where('employment_status', Employee::STATUS_ON_NOTICE)->count();
        $onProbation = Employee::onProbation()->count();

        $byDepartment = Employee::active()
            ->selectRaw('department_id, count(*) as count')
            ->groupBy('department_id')
            ->with('department:id,name')
            ->get()
            ->mapWithKeys(fn($row) => [$row->department?->name ?? 'Unassigned' => $row->count]);

        $byEmploymentType = Employee::active()
            ->selectRaw('employment_type, count(*) as count')
            ->groupBy('employment_type')
            ->pluck('count', 'employment_type');

        $joinedThisMonth = Employee::active()
            ->whereBetween('joining_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        return [
            'total_active' => $active,
            'on_notice' => $onNotice,
            'on_probation' => $onProbation,
            'joined_this_month' => $joinedThisMonth,
            'by_department' => $byDepartment,
            'by_employment_type' => $byEmploymentType,
        ];
    }
}
