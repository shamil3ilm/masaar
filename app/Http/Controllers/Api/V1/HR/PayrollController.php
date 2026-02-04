<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Http\Resources\HR\PayrollPeriodResource;
use App\Http\Resources\HR\PayslipResource;
use App\Models\HR\Employee;
use App\Models\HR\PayrollPeriod;
use App\Models\HR\Payslip;
use App\Services\HR\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollController extends Controller
{
    public function __construct(
        private PayrollService $payrollService
    ) {}

    /**
     * List payroll periods.
     */
    public function periods(Request $request): AnonymousResourceCollection
    {
        $query = PayrollPeriod::query()
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->year, fn($q, $year) => $q->whereYear('start_date', $year))
            ->orderBy('start_date', 'desc');

        $periods = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return PayrollPeriodResource::collection($periods);
    }

    /**
     * Create a payroll period.
     */
    public function createPeriod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'payment_date' => 'nullable|date|after_or_equal:end_date',
        ]);

        $period = $this->payrollService->createPeriod($validated);

        return response()->json([
            'message' => 'Payroll period created successfully.',
            'data' => new PayrollPeriodResource($period),
        ], 201);
    }

    /**
     * Show a payroll period with payslips.
     */
    public function showPeriod(PayrollPeriod $payrollPeriod): PayrollPeriodResource
    {
        return new PayrollPeriodResource(
            $payrollPeriod->load(['payslips.employee'])
        );
    }

    /**
     * Generate payslips for a period.
     */
    public function generatePayslips(PayrollPeriod $payrollPeriod): JsonResponse
    {
        $count = $this->payrollService->generatePayslips($payrollPeriod);

        return response()->json([
            'message' => "Generated {$count} payslips.",
            'data' => new PayrollPeriodResource($payrollPeriod->fresh()),
        ]);
    }

    /**
     * Get period summary.
     */
    public function periodSummary(PayrollPeriod $payrollPeriod): JsonResponse
    {
        $summary = $this->payrollService->getPeriodSummary($payrollPeriod);

        return response()->json(['data' => $summary]);
    }

    /**
     * Close a payroll period.
     */
    public function closePeriod(PayrollPeriod $payrollPeriod): JsonResponse
    {
        $period = $this->payrollService->closePeriod($payrollPeriod);

        return response()->json([
            'message' => 'Payroll period closed successfully.',
            'data' => new PayrollPeriodResource($period),
        ]);
    }

    /**
     * List payslips.
     */
    public function payslips(Request $request): AnonymousResourceCollection
    {
        $query = Payslip::with(['employee', 'payrollPeriod'])
            ->when($request->period_id, fn($q, $id) => $q->forPeriod($id))
            ->when($request->employee_id, fn($q, $id) => $q->forEmployee($id))
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->orderBy('created_at', 'desc');

        $payslips = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return PayslipResource::collection($payslips);
    }

    /**
     * Show a specific payslip.
     */
    public function showPayslip(Payslip $payslip): PayslipResource
    {
        return new PayslipResource(
            $payslip->load(['employee', 'payrollPeriod', 'items.salaryComponent', 'employeeSalary'])
        );
    }

    /**
     * Generate single employee payslip.
     */
    public function generateSinglePayslip(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $payslip = $this->payrollService->generatePayslip($payrollPeriod, $employee);

        return response()->json([
            'message' => 'Payslip generated successfully.',
            'data' => new PayslipResource($payslip),
        ], 201);
    }

    /**
     * Submit payslip for approval.
     */
    public function submitPayslip(Payslip $payslip): JsonResponse
    {
        if ($payslip->status !== Payslip::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft payslips can be submitted.'], 422);
        }

        $payslip->update(['status' => Payslip::STATUS_PENDING]);

        return response()->json([
            'message' => 'Payslip submitted for approval.',
            'data' => new PayslipResource($payslip->fresh()),
        ]);
    }

    /**
     * Approve a payslip.
     */
    public function approvePayslip(Payslip $payslip): JsonResponse
    {
        $payslip = $this->payrollService->approvePayslip($payslip);

        return response()->json([
            'message' => 'Payslip approved successfully.',
            'data' => new PayslipResource($payslip),
        ]);
    }

    /**
     * Mark payslip as paid.
     */
    public function markAsPaid(Request $request, Payslip $payslip): JsonResponse
    {
        $validated = $request->validate([
            'payment_mode' => 'required|string|max:20',
            'payment_reference' => 'nullable|string|max:100',
        ]);

        $payslip = $this->payrollService->markAsPaid(
            $payslip,
            $validated['payment_mode'],
            $validated['payment_reference'] ?? null
        );

        return response()->json([
            'message' => 'Payslip marked as paid.',
            'data' => new PayslipResource($payslip),
        ]);
    }

    /**
     * Bulk approve payslips.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payslip_ids' => 'required|array',
            'payslip_ids.*' => 'exists:payslips,id',
        ]);

        $count = 0;
        foreach ($validated['payslip_ids'] as $id) {
            $payslip = Payslip::find($id);
            if ($payslip && $payslip->status === Payslip::STATUS_PENDING) {
                $this->payrollService->approvePayslip($payslip);
                $count++;
            }
        }

        return response()->json([
            'message' => "Approved {$count} payslips.",
        ]);
    }

    /**
     * Bulk mark payslips as paid.
     */
    public function bulkPay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payslip_ids' => 'required|array',
            'payslip_ids.*' => 'exists:payslips,id',
            'payment_mode' => 'required|string|max:20',
            'payment_reference' => 'nullable|string|max:100',
        ]);

        $count = 0;
        foreach ($validated['payslip_ids'] as $id) {
            $payslip = Payslip::find($id);
            if ($payslip && $payslip->status === Payslip::STATUS_APPROVED) {
                $this->payrollService->markAsPaid(
                    $payslip,
                    $validated['payment_mode'],
                    $validated['payment_reference'] ?? null
                );
                $count++;
            }
        }

        return response()->json([
            'message' => "Paid {$count} payslips.",
        ]);
    }
}
