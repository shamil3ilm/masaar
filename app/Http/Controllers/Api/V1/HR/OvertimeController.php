<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\OvertimeRequest;
use App\Services\HR\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvertimeController extends Controller
{
    public function __construct(
        private OvertimeService $overtimeService
    ) {}

    /**
     * List overtime requests with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = OvertimeRequest::with(['employee', 'policy', 'approver'])
            ->when($request->employee_id, fn($q, $v) => $q->forEmployee((int) $v))
            ->when($request->status, fn($q, $v) => $q->where('status', $v))
            ->when($request->from_date, fn($q, $v) => $q->where('ot_date', '>=', $v))
            ->when($request->to_date, fn($q, $v) => $q->where('ot_date', '<=', $v))
            ->orderBy('ot_date', 'desc');

        return $this->paginated($query->paginate($request->integer('per_page', 15)));
    }

    /**
     * Create an overtime request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'policy_id'   => 'required|exists:overtime_policies,id',
            'ot_date'     => 'required|date',
            'ot_start'    => 'required|date_format:H:i',
            'ot_end'      => 'required|date_format:H:i|after:ot_start',
            'ot_hours'    => 'required|numeric|min:0.25|max:24',
            'reason'      => 'nullable|string|max:500',
            'day_type'    => 'nullable|in:weekday,weekend,holiday',
        ]);

        try {
            $otRequest = $this->overtimeService->requestOvertime($validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success($otRequest->load(['employee', 'policy']), 'Overtime request submitted.', 201);
    }

    /**
     * Show a single overtime request.
     */
    public function show(OvertimeRequest $overtime): JsonResponse
    {
        return $this->success($overtime->load(['employee', 'policy', 'approver']));
    }

    /**
     * Approve an overtime request.
     */
    public function approve(OvertimeRequest $overtimeRequest): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->overtimeService->approve($overtimeRequest),
            'Overtime request approved.',
            'STATE_ERROR'
        );
    }

    /**
     * Reject an overtime request.
     */
    public function reject(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        return $this->tryAction(
            fn() => $this->overtimeService->reject($overtimeRequest, $validated['reason']),
            'Overtime request rejected.',
            'STATE_ERROR'
        );
    }

    /**
     * Get monthly OT summary for an employee (for payroll).
     */
    public function monthlyOtSummary(int $employeeId, int $year, int $month): JsonResponse
    {
        if ($month < 1 || $month > 12) {
            return $this->error('Invalid month value.', 'VALIDATION_ERROR', 422);
        }

        $summary = $this->overtimeService->getMonthlyOtSummary($employeeId, $year, $month);

        return $this->success($summary);
    }

    /**
     * Update overtime request (alias for PUT; Laravel apiResource maps to update).
     */
    public function update(Request $request, OvertimeRequest $overtime): JsonResponse
    {
        // Only pending requests may be updated
        if (!$overtime->isPending()) {
            return $this->error('Only pending requests can be modified.', 'STATE_ERROR', 422);
        }

        $validated = $request->validate([
            'ot_date'  => 'sometimes|date',
            'ot_start' => 'sometimes|date_format:H:i',
            'ot_end'   => 'sometimes|date_format:H:i',
            'ot_hours' => 'sometimes|numeric|min:0.25|max:24',
            'reason'   => 'nullable|string|max:500',
            'day_type' => 'sometimes|in:weekday,weekend,holiday',
        ]);

        $overtime->update($validated);

        return $this->success($overtime->fresh(), 'Overtime request updated.');
    }

    /**
     * Delete an overtime request (only if pending).
     */
    public function destroy(OvertimeRequest $overtime): JsonResponse
    {
        if (!$overtime->isPending()) {
            return $this->error('Only pending requests can be deleted.', 'STATE_ERROR', 422);
        }

        $overtime->delete();

        return $this->success(null, 'Overtime request deleted.');
    }
}
