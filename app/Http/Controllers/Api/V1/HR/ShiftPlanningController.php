<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Employee;
use App\Models\HR\ShiftPattern;
use App\Models\HR\ShiftRoster;
use App\Models\HR\ShiftSwapRequest;
use App\Services\HR\ShiftPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftPlanningController extends Controller
{
    public function __construct(
        private ShiftPlanningService $shiftService
    ) {}

    /**
     * List shift patterns.
     */
    public function indexPatterns(Request $request): JsonResponse
    {
        $patterns = ShiftPattern::query()
            ->when($request->boolean('active_only', false), fn($q) => $q->active())
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($patterns);
    }

    /**
     * Create a shift pattern.
     */
    public function storePattern(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:20',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'break_minutes' => 'integer|min:0|max:480',
            'days_of_week' => 'required|array',
            'days_of_week.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'crosses_midnight' => 'boolean',
            'color_hex' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'notes' => 'nullable|string|max:500',
        ]);

        $pattern = ShiftPattern::create(array_merge($validated, [
            'organization_id' => auth()->user()->organization_id,
            'is_active' => true,
        ]));

        return $this->created($pattern, 'Shift pattern created successfully.');
    }

    /**
     * Update a shift pattern.
     */
    public function updatePattern(Request $request, ShiftPattern $shiftPattern): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string|max:100',
            'start_time' => 'date_format:H:i',
            'end_time' => 'date_format:H:i',
            'break_minutes' => 'integer|min:0|max:480',
            'days_of_week' => 'array',
            'days_of_week.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'crosses_midnight' => 'boolean',
            'color_hex' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'boolean',
        ]);

        $shiftPattern->update($validated);

        return $this->success($shiftPattern->fresh(), 'Shift pattern updated successfully.');
    }

    /**
     * List rosters.
     */
    public function indexRosters(Request $request): JsonResponse
    {
        $rosters = ShiftRoster::query()
            ->when($request->status, fn($q, $v) => $q->byStatus($v))
            ->when($request->branch_id, fn($q, $v) => $q->where('branch_id', $v))
            ->when($request->department_id, fn($q, $v) => $q->where('department_id', $v))
            ->withCount('lines')
            ->orderByDesc('roster_period_start')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($rosters);
    }

    /**
     * Create a roster.
     */
    public function storeRoster(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'branch_id' => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'roster_period_start' => 'required|date',
            'roster_period_end' => 'required|date|after:roster_period_start',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $roster = $this->shiftService->createRoster($validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->created($roster, 'Roster created successfully.');
    }

    /**
     * Show a roster with lines.
     */
    public function showRoster(Request $request, ShiftRoster $shiftRoster): JsonResponse
    {
        $roster = $shiftRoster->load(['lines.employee', 'lines.shiftPattern', 'branch', 'department']);

        return $this->success($roster);
    }

    /**
     * Assign a shift to an employee in a roster.
     */
    public function assignShift(Request $request, ShiftRoster $shiftRoster): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'shift_date' => 'required|date',
            'shift_pattern_id' => 'nullable|exists:shift_patterns,id',
            'is_day_off' => 'boolean',
            'override_start_time' => 'nullable|date_format:H:i',
            'override_end_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:500',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $pattern = isset($validated['shift_pattern_id'])
            ? ShiftPattern::findOrFail($validated['shift_pattern_id'])
            : null;

        return $this->tryAction(
            fn() => $this->shiftService->assignShift($shiftRoster, $employee, $validated['shift_date'], $pattern, $validated)->load('employee', 'shiftPattern'),
            'Shift assigned successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Bulk assign a shift pattern across a date range.
     */
    public function bulkAssign(Request $request, ShiftRoster $shiftRoster): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'shift_pattern_id' => 'required|exists:shift_patterns,id',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $pattern = ShiftPattern::findOrFail($validated['shift_pattern_id']);

        try {
            $count = $this->shiftService->bulkAssignShift(
                $shiftRoster,
                $employee,
                $pattern,
                $validated['from_date'],
                $validated['to_date']
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success(['assigned_days' => $count], "Assigned {$count} days.");
    }

    /**
     * Publish a roster.
     */
    public function publishRoster(ShiftRoster $shiftRoster): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->shiftService->publishRoster($shiftRoster),
            'Roster published successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * List swap requests.
     */
    public function listSwapRequests(Request $request): JsonResponse
    {
        $swaps = ShiftSwapRequest::with(['requester', 'requestedEmployee'])
            ->when($request->status, fn($q, $v) => $q->byStatus($v))
            ->when($request->employee_id, fn($q, $v) => $q->where(
                fn($q2) => $q2->where('requester_id', $v)->orWhere('requested_employee_id', $v)
            ))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($swaps);
    }

    /**
     * Request a shift swap.
     */
    public function requestSwap(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'requested_employee_id' => 'required|exists:employees,id',
            'requester_shift_date' => 'required|date',
            'requested_shift_date' => 'required|date',
            'reason' => 'nullable|string|max:500',
        ]);

        $requester = Employee::where('user_id', auth()->id())->firstOrFail();
        $requestedEmployee = Employee::findOrFail($validated['requested_employee_id']);

        try {
            $swap = $this->shiftService->requestSwap(
                $requester,
                $requestedEmployee,
                $validated['requester_shift_date'],
                $validated['requested_shift_date'],
                $validated['reason'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->created($swap->load('requester', 'requestedEmployee'), 'Swap request submitted.');
    }

    /**
     * Approve a swap request (manager action).
     */
    public function approveSwap(ShiftSwapRequest $shiftSwapRequest): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->shiftService->approveSwap($shiftSwapRequest),
            'Swap approved.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Reject a swap request.
     */
    public function rejectSwap(Request $request, ShiftSwapRequest $shiftSwapRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        return $this->tryAction(
            fn() => $this->shiftService->rejectSwap($shiftSwapRequest, $validated['reason']),
            'Swap request rejected.',
            'VALIDATION_ERROR'
        );
    }
}
