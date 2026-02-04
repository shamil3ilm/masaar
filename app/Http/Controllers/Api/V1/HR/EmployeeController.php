<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Http\Resources\HR\EmployeeResource;
use App\Models\HR\Employee;
use App\Models\HR\SalaryStructure;
use App\Services\HR\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function __construct(
        private EmployeeService $employeeService
    ) {}

    /**
     * List employees with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Employee::with(['department', 'designation', 'branch'])
            ->when($request->status, fn($q, $status) => $q->where('employment_status', $status))
            ->when($request->department_id, fn($q, $id) => $q->inDepartment($id))
            ->when($request->designation_id, fn($q, $id) => $q->withDesignation($id))
            ->when($request->employment_type, fn($q, $type) => $q->where('employment_type', $type))
            ->when($request->active === 'true', fn($q) => $q->active())
            ->when($request->on_probation === 'true', fn($q) => $q->onProbation())
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy($request->sort_by ?? 'first_name', $request->sort_order ?? 'asc');

        $employees = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return EmployeeResource::collection($employees);
    }

    /**
     * Store a new employee.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_number' => 'nullable|string|max:50',
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'marital_status' => 'nullable|in:single,married,divorced,widowed',
            'nationality' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:200',
            'personal_email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:30',
            'mobile' => 'nullable|string|max:30',
            'address_line_1' => 'nullable|string|max:200',
            'address_line_2' => 'nullable|string|max:200',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country_code' => 'nullable|string|size:2',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'nullable|exists:designations,id',
            'branch_id' => 'nullable|exists:branches,id',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'joining_date' => 'nullable|date',
            'employment_type' => 'nullable|in:full_time,part_time,contract,intern,probation',
            'national_id' => 'nullable|string|max:50',
            'passport_number' => 'nullable|string|max:50',
            'passport_expiry' => 'nullable|date',
            'tax_number' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_ifsc_code' => 'nullable|string|max:20',
            'bank_iban' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        $employee = $this->employeeService->create($validated);

        return response()->json([
            'message' => 'Employee created successfully.',
            'data' => new EmployeeResource($employee),
        ], 201);
    }

    /**
     * Show a specific employee.
     */
    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource(
            $employee->load([
                'department',
                'designation',
                'branch',
                'reportingManager',
                'currentSalary.salaryStructure',
                'documents',
                'qualifications',
                'experiences',
            ])
        );
    }

    /**
     * Update an employee.
     */
    public function update(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'marital_status' => 'nullable|in:single,married,divorced,widowed',
            'nationality' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:200',
            'personal_email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:30',
            'mobile' => 'nullable|string|max:30',
            'address_line_1' => 'nullable|string|max:200',
            'address_line_2' => 'nullable|string|max:200',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country_code' => 'nullable|string|size:2',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'nullable|exists:designations,id',
            'branch_id' => 'nullable|exists:branches,id',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'national_id' => 'nullable|string|max:50',
            'passport_number' => 'nullable|string|max:50',
            'passport_expiry' => 'nullable|date',
            'tax_number' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_ifsc_code' => 'nullable|string|max:20',
            'bank_iban' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        $employee = $this->employeeService->update($employee, $validated);

        return response()->json([
            'message' => 'Employee updated successfully.',
            'data' => new EmployeeResource($employee),
        ]);
    }

    /**
     * Assign salary to employee.
     */
    public function assignSalary(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'salary_structure_id' => 'required|exists:salary_structures,id',
            'effective_from' => 'required|date',
            'components' => 'required|array',
            'components.*' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        $structure = SalaryStructure::findOrFail($validated['salary_structure_id']);

        $salary = $this->employeeService->assignSalary(
            $employee,
            $structure,
            $validated['components'],
            new \DateTime($validated['effective_from']),
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Salary assigned successfully.',
            'data' => $salary,
        ]);
    }

    /**
     * Confirm employee (end probation).
     */
    public function confirm(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'confirmation_date' => 'nullable|date',
        ]);

        $employee = $this->employeeService->confirm(
            $employee,
            isset($validated['confirmation_date']) ? new \DateTime($validated['confirmation_date']) : null
        );

        return response()->json([
            'message' => 'Employee confirmed successfully.',
            'data' => new EmployeeResource($employee),
        ]);
    }

    /**
     * Terminate employee.
     */
    public function terminate(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'termination_date' => 'required|date',
            'reason' => 'required|string|max:500',
            'status' => 'nullable|in:terminated,resigned,absconded',
        ]);

        $employee = $this->employeeService->terminate(
            $employee,
            new \DateTime($validated['termination_date']),
            $validated['reason'],
            $validated['status'] ?? 'terminated'
        );

        return response()->json([
            'message' => 'Employee terminated successfully.',
            'data' => new EmployeeResource($employee),
        ]);
    }

    /**
     * Get employee statistics.
     */
    public function statistics(): JsonResponse
    {
        $stats = $this->employeeService->getStatistics();

        return response()->json(['data' => $stats]);
    }

    /**
     * Get employees with expiring documents.
     */
    public function expiringDocuments(Request $request): JsonResponse
    {
        $days = (int) ($request->days ?? 30);
        $documents = $this->employeeService->getExpiringDocuments($days);

        return response()->json(['data' => $documents]);
    }
}
