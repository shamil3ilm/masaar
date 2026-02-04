<?php

declare(strict_types=1);

namespace App\Services\Core\Importers;

use App\Models\Core\ImportJob;
use App\Models\HR\Department;
use App\Models\HR\Designation;
use App\Models\HR\Employee;
use App\Services\Core\ImporterInterface;

class EmployeeImporter implements ImporterInterface
{
    public function importRow(array $data, ImportJob $importJob, array $options = []): mixed
    {
        // Check for existing employee
        $existing = null;
        if ($options['update_existing'] ?? false) {
            $existing = Employee::where('organization_id', $importJob->organization_id)
                ->where(function ($query) use ($data) {
                    if (!empty($data['email'])) {
                        $query->orWhere('email', $data['email']);
                    }
                    if (!empty($data['employee_number'])) {
                        $query->orWhere('employee_number', $data['employee_number']);
                    }
                    if (!empty($data['national_id'])) {
                        $query->orWhere('national_id', $data['national_id']);
                    }
                })
                ->first();
        }

        // Resolve department
        $departmentId = null;
        if (!empty($data['department'])) {
            $department = Department::firstOrCreate(
                [
                    'organization_id' => $importJob->organization_id,
                    'name' => $data['department'],
                ],
                [
                    'code' => strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $data['department']), 0, 10)),
                    'is_active' => true,
                ]
            );
            $departmentId = $department->id;
        }

        // Resolve designation
        $designationId = null;
        if (!empty($data['designation'])) {
            $designation = Designation::firstOrCreate(
                [
                    'organization_id' => $importJob->organization_id,
                    'name' => $data['designation'],
                ],
                [
                    'is_active' => true,
                ]
            );
            $designationId = $designation->id;
        }

        // Generate employee number if not provided
        $employeeNumber = $data['employee_number'] ?? null;
        if (!$employeeNumber && !$existing) {
            $lastEmployee = Employee::where('organization_id', $importJob->organization_id)
                ->orderByDesc('id')
                ->first();
            $nextNum = $lastEmployee ? ((int) preg_replace('/\D/', '', $lastEmployee->employee_number)) + 1 : 1;
            $employeeNumber = 'EMP' . str_pad((string) $nextNum, 5, '0', STR_PAD_LEFT);
        }

        $employeeData = [
            'organization_id' => $importJob->organization_id,
            'employee_number' => $employeeNumber,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'nationality' => $data['nationality'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'department_id' => $departmentId,
            'designation_id' => $designationId,
            'hire_date' => $data['hire_date'] ?? now()->format('Y-m-d'),
            'employment_type' => $data['employment_type'] ?? 'full_time',
            'employment_status' => 'active',
            'is_active' => true,
        ];

        if ($existing) {
            $existing->update(array_filter($employeeData, fn ($v) => $v !== null));
            $employee = $existing;
        } else {
            $employee = Employee::create($employeeData);
        }

        // Create salary structure if basic salary provided
        if (!empty($data['basic_salary']) && $data['basic_salary'] > 0) {
            $this->createSalaryStructure($employee, (float) $data['basic_salary'], $data);
        }

        return $employee;
    }

    protected function createSalaryStructure(Employee $employee, float $basicSalary, array $data): void
    {
        // This would create or update the employee's salary structure
        // Implementation depends on salary structure model
        $employee->update([
            'basic_salary' => $basicSalary,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'iban' => $data['iban'] ?? null,
        ]);
    }
}
