<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Services\HR\HRReportService;
use App\Services\HR\StatutoryDeductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HRReportsController extends Controller
{
    public function __construct(
        protected HRReportService $reportService,
        protected StatutoryDeductionService $statutoryService
    ) {}

    /**
     * Get HR Dashboard Summary.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->reportService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->reportService->getDashboardSummary();

        return response()->json(['data' => $data]);
    }

    /**
     * Get Headcount Report.
     */
    public function headcount(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'as_of_date' => 'nullable|date',
            'department_id' => 'nullable|integer|exists:departments,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->reportService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->reportService->generateHeadcountReport(
            $request->get('as_of_date', now()->toDateString()),
            $request->get('department_id')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Turnover Report.
     */
    public function turnover(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->reportService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->reportService->generateTurnoverReport(
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Attendance Report.
     */
    public function attendance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'department_id' => 'nullable|integer|exists:departments,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->reportService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->reportService->generateAttendanceReport(
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('department_id')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Leave Analysis Report.
     */
    public function leaveAnalysis(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'department_id' => 'nullable|integer|exists:departments,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->reportService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->reportService->generateLeaveReport(
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('department_id')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Payroll Summary Report.
     */
    public function payrollSummary(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $this->reportService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->reportService->generatePayrollReport(
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Get Statutory Deduction Configuration.
     */
    public function statutoryConfig(Request $request): JsonResponse
    {
        $user = $request->user();
        $countryCode = $request->get('country_code', $user->organization->country_code);

        $config = $this->statutoryService->getConfiguration($countryCode);

        $response = [
            'country_code' => $countryCode,
            'schemes' => $config,
        ];

        // Add India-specific data
        if ($countryCode === 'IN') {
            $response['professional_tax_slabs'] = [
                'states' => ['MH', 'KA', 'TN', 'GJ', 'WB', 'AP', 'TS', 'KL'],
                'slabs' => $this->statutoryService->getIndiaPtSlabs($request->get('state', 'MH')),
            ];
            $response['income_tax_slabs'] = [
                'new_regime' => $this->statutoryService->getIndiaTaxSlabs('new'),
                'old_regime' => $this->statutoryService->getIndiaTaxSlabs('old'),
            ];
        }

        return response()->json(['data' => $response]);
    }

    /**
     * Calculate statutory deductions preview.
     */
    public function calculateStatutory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'gross_salary' => 'required|numeric|min:0',
            'country_code' => 'nullable|string|size:2',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'state' => 'nullable|string', // For India PT
            'tax_regime' => 'nullable|string|in:new,old', // For India TDS
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $countryCode = $request->get('country_code', $user->organization->country_code);
        $grossSalary = (float) $request->get('gross_salary');

        // If employee_id provided, use their data
        $employee = null;
        if ($request->has('employee_id')) {
            $employee = \App\Models\HR\Employee::where('organization_id', $user->organization_id)
                ->find($request->get('employee_id'));
        }

        // Create mock employee if not provided
        if (!$employee) {
            $employee = new \App\Models\HR\Employee([
                'organization_id' => $user->organization_id,
                'nationality' => $countryCode,
                'work_state' => $request->get('state', 'MH'),
                'tax_regime' => $request->get('tax_regime', 'new'),
            ]);
            $employee->organization = $user->organization;
        }

        $deductions = $this->statutoryService->calculateDeductions($employee, $grossSalary, $countryCode);

        return response()->json([
            'data' => [
                'gross_salary' => $grossSalary,
                'country_code' => $countryCode,
                ...$deductions,
                'net_after_statutory' => $grossSalary - $deductions['total_employee'],
            ],
        ]);
    }

    /**
     * Generate statutory compliance report.
     */
    public function statutoryCompliance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $data = $this->statutoryService->generateComplianceReport(
            $user->organization_id,
            $request->get('start_date'),
            $request->get('end_date'),
            $user->organization->country_code
        );

        return response()->json(['data' => $data]);
    }
}
