<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Attendance;
use App\Models\HR\Employee;
use App\Models\HR\LeaveBalance;
use App\Models\HR\LeaveRequest;
use App\Models\HR\Payslip;
use App\Services\HR\AttendanceService;
use App\Services\HR\LeaveService;
use App\Services\HR\StatutoryDeductionService;
use App\Services\Print\PrintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class EmployeeSelfServiceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendanceService,
        protected LeaveService $leaveService,
        protected StatutoryDeductionService $statutoryService,
        protected PrintService $printService
    ) {}

    /**
     * Get current user's employee profile.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $employee->load([
            'department',
            'designation',
            'branch',
            'reportingManager',
            'currentSalary.components.salaryComponent',
        ]);

        return response()->json([
            'data' => [
                'employee' => $employee,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
            ],
        ]);
    }

    /**
     * Get employee's attendance for current month.
     */
    public function myAttendance(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $month = $request->get('month', now()->format('Y-m'));
        $startDate = \Carbon\Carbon::parse($month)->startOfMonth();
        $endDate = \Carbon\Carbon::parse($month)->endOfMonth();

        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->orderBy('attendance_date')
            ->get();

        $summary = $this->attendanceService->getEmployeeSummary($employee, $startDate, $endDate);

        return response()->json([
            'data' => [
                'month' => $month,
                'records' => $attendance,
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * Check in for current day.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'location' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $attendance = $this->attendanceService->checkIn($employee, [
            'location' => $request->get('location'),
            'latitude' => $request->get('latitude'),
            'longitude' => $request->get('longitude'),
            'notes' => $request->get('notes'),
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'data' => $attendance,
            'message' => 'Checked in successfully at ' . $attendance->check_in_time->format('H:i'),
        ]);
    }

    /**
     * Check out for current day.
     */
    public function checkOut(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $attendance = $this->attendanceService->checkOut($employee, [
            'location' => $request->get('location'),
            'latitude' => $request->get('latitude'),
            'longitude' => $request->get('longitude'),
            'notes' => $request->get('notes'),
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'data' => $attendance,
            'message' => 'Checked out successfully at ' . $attendance->check_out_time->format('H:i'),
        ]);
    }

    /**
     * Get employee's leave balances.
     */
    public function myLeaveBalances(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $balances = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $request->get('year', now()->year))
            ->with('leaveType')
            ->get();

        return response()->json([
            'data' => $balances->map(fn($b) => [
                'leave_type' => $b->leaveType->name,
                'leave_type_code' => $b->leaveType->code,
                'entitled' => $b->entitled_days,
                'used' => $b->used_days,
                'pending' => $b->pending_days,
                'available' => $b->available_days,
                'carried_forward' => $b->carried_forward,
            ]),
        ]);
    }

    /**
     * Get employee's leave requests.
     */
    public function myLeaveRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $query = LeaveRequest::where('employee_id', $employee->id)
            ->with('leaveType')
            ->orderByDesc('created_at');

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('year')) {
            $query->whereYear('start_date', $request->get('year'));
        }

        $requests = $query->paginate($request->get('per_page', 15));

        return response()->json($requests);
    }

    /**
     * Submit a leave request.
     */
    public function submitLeaveRequest(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'half_day' => 'nullable|boolean',
            'half_day_type' => 'nullable|string|in:first_half,second_half',
            'reason' => 'required|string|max:1000',
            'emergency_contact' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $leaveRequest = $this->leaveService->createRequest($employee, $validator->validated());

            return response()->json([
                'data' => $leaveRequest->load('leaveType'),
                'message' => 'Leave request submitted successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Cancel a leave request.
     */
    public function cancelLeaveRequest(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $leaveRequest = LeaveRequest::where('employee_id', $employee->id)
            ->findOrFail($id);

        if (!in_array($leaveRequest->status, ['draft', 'pending'])) {
            return response()->json(['error' => 'Cannot cancel this request'], 400);
        }

        $this->leaveService->cancelRequest($leaveRequest, $request->get('reason'));

        return response()->json(['message' => 'Leave request cancelled']);
    }

    /**
     * Get employee's payslips.
     */
    public function myPayslips(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $query = Payslip::where('employee_id', $employee->id)
            ->with('payrollPeriod')
            ->orderByDesc('created_at');

        if ($request->has('year')) {
            $query->whereYear('created_at', $request->get('year'));
        }

        $payslips = $query->paginate($request->get('per_page', 12));

        return response()->json($payslips);
    }

    /**
     * Get single payslip details.
     */
    public function showPayslip(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $payslip = Payslip::where('employee_id', $employee->id)
            ->with(['items.salaryComponent', 'payrollPeriod', 'employee.department', 'employee.designation'])
            ->findOrFail($id);

        return response()->json(['data' => $payslip]);
    }

    /**
     * Download payslip PDF.
     */
    public function downloadPayslip(Request $request, int $id): Response|JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $payslip = Payslip::where('employee_id', $employee->id)
            ->with(['items.salaryComponent', 'payrollPeriod', 'employee.department', 'employee.designation', 'employee.organization'])
            ->findOrFail($id);

        $pdf = $this->printService->generatePdf('payslip', $payslip, 'a4');

        $filename = "payslip-{$payslip->payslip_number}.pdf";

        if ($request->boolean('download')) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    /**
     * Get salary breakdown with statutory deductions preview.
     */
    public function salaryBreakdown(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $salary = $employee->currentSalary;

        if (!$salary) {
            return response()->json(['error' => 'No salary structure assigned'], 404);
        }

        $salary->load('components.salaryComponent');

        $grossSalary = $salary->gross_salary;

        // Get statutory deductions preview
        $statutory = $this->statutoryService->calculateDeductions(
            $employee,
            $grossSalary,
            $employee->organization->country_code
        );

        return response()->json([
            'data' => [
                'gross_salary' => $grossSalary,
                'currency' => $salary->currency_code,
                'earnings' => $salary->getEarnings()->map(fn($c) => [
                    'name' => $c->salaryComponent->name,
                    'amount' => $c->amount,
                    'is_taxable' => $c->salaryComponent->is_taxable,
                ]),
                'deductions' => $salary->getDeductions()->map(fn($c) => [
                    'name' => $c->salaryComponent->name,
                    'amount' => $c->amount,
                ]),
                'statutory_deductions' => $statutory['employee_deductions'],
                'employer_contributions' => $statutory['employer_contributions'],
                'summary' => [
                    'total_earnings' => $grossSalary,
                    'total_deductions' => $salary->getDeductions()->sum('amount') + $statutory['total_employee'],
                    'total_statutory' => $statutory['total_employee'],
                    'net_salary' => $grossSalary - $salary->getDeductions()->sum('amount') - $statutory['total_employee'],
                ],
            ],
        ]);
    }

    /**
     * Get employee's loans.
     */
    public function myLoans(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $loans = \App\Models\HR\EmployeeLoan::where('employee_id', $employee->id)
            ->with('repayments')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $loans->map(fn($loan) => [
                'id' => $loan->id,
                'loan_type' => $loan->loan_type,
                'principal_amount' => $loan->principal_amount,
                'interest_rate' => $loan->interest_rate,
                'total_amount' => $loan->total_amount,
                'emi_amount' => $loan->emi_amount,
                'tenure_months' => $loan->tenure_months,
                'disbursement_date' => $loan->disbursement_date,
                'total_paid' => $loan->repayments->where('status', 'paid')->sum('total_amount'),
                'outstanding' => $loan->outstanding_amount,
                'status' => $loan->status,
                'repayments' => $loan->repayments->map(fn($r) => [
                    'due_date' => $r->due_date,
                    'amount' => $r->total_amount,
                    'status' => $r->status,
                    'paid_date' => $r->paid_date,
                ]),
            ]),
        ]);
    }

    /**
     * Get employee's documents.
     */
    public function myDocuments(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['error' => 'No employee record found'], 404);
        }

        $documents = \App\Models\HR\EmployeeDocument::where('employee_id', $employee->id)
            ->orderBy('document_type')
            ->get();

        return response()->json([
            'data' => $documents->map(fn($doc) => [
                'id' => $doc->id,
                'document_type' => $doc->document_type,
                'document_number' => $doc->document_number,
                'issue_date' => $doc->issue_date,
                'expiry_date' => $doc->expiry_date,
                'is_expired' => $doc->expiry_date ? $doc->expiry_date->isPast() : false,
                'days_to_expiry' => $doc->expiry_date ? now()->diffInDays($doc->expiry_date, false) : null,
            ]),
        ]);
    }

    /**
     * Get employee directory (colleagues).
     */
    public function directory(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Employee::where('organization_id', $user->organization_id)
            ->where('employment_status', 'active')
            ->with(['department', 'designation', 'branch']);

        if ($request->has('department_id')) {
            $query->where('department_id', $request->get('department_id'));
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('employee_number', 'like', "%{$search}%")
                    ->orWhere('work_email', 'like', "%{$search}%");
            });
        }

        $employees = $query->select([
            'id', 'employee_number', 'first_name', 'last_name',
            'work_email', 'work_phone', 'department_id', 'designation_id',
            'branch_id', 'profile_photo_url'
        ])
            ->orderBy('first_name')
            ->paginate($request->get('per_page', 20));

        return response()->json($employees);
    }

    /**
     * Get organization holidays.
     */
    public function holidays(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = $request->get('year', now()->year);

        $holidays = \App\Models\HR\Holiday::where('organization_id', $user->organization_id)
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get();

        return response()->json([
            'data' => $holidays,
            'year' => $year,
        ]);
    }
}
