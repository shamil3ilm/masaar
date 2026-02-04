<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Http\Resources\HR\LeaveRequestResource;
use App\Models\HR\Employee;
use App\Models\HR\LeaveRequest;
use App\Models\HR\LeaveType;
use App\Services\HR\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveController extends Controller
{
    public function __construct(
        private LeaveService $leaveService
    ) {}

    /**
     * List leave types.
     */
    public function leaveTypes(): JsonResponse
    {
        $types = LeaveType::active()->ordered()->get();

        return response()->json(['data' => $types]);
    }

    /**
     * List leave requests with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = LeaveRequest::with(['employee', 'leaveType', 'approver'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->employee_id, fn($q, $id) => $q->forEmployee($id))
            ->when($request->leave_type_id, fn($q, $id) => $q->where('leave_type_id', $id))
            ->when($request->pending === 'true', fn($q) => $q->pending())
            ->when($request->start_date, fn($q, $date) => $q->where('from_date', '>=', $date))
            ->when($request->end_date, fn($q, $date) => $q->where('to_date', '<=', $date))
            ->orderBy($request->sort_by ?? 'from_date', $request->sort_order ?? 'desc');

        $requests = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return LeaveRequestResource::collection($requests);
    }

    /**
     * Store a new leave request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'from_date' => 'required|date|after_or_equal:today',
            'to_date' => 'required|date|after_or_equal:from_date',
            'is_half_day' => 'nullable|boolean',
            'half_day_type' => 'nullable|required_if:is_half_day,true|in:first_half,second_half',
            'reason' => 'nullable|string|max:500',
            'contact_during_leave' => 'nullable|string|max:100',
            'address_during_leave' => 'nullable|string|max:500',
            'attachment_path' => 'nullable|string|max:500',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $leaveRequest = $this->leaveService->createRequest($employee, $validated);

        return response()->json([
            'message' => 'Leave request created successfully.',
            'data' => new LeaveRequestResource($leaveRequest),
        ], 201);
    }

    /**
     * Show a specific leave request.
     */
    public function show(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        return new LeaveRequestResource(
            $leaveRequest->load(['employee', 'leaveType', 'approver'])
        );
    }

    /**
     * Submit leave request for approval.
     */
    public function submit(LeaveRequest $leaveRequest): JsonResponse
    {
        $request = $this->leaveService->submit($leaveRequest);

        return response()->json([
            'message' => 'Leave request submitted successfully.',
            'data' => new LeaveRequestResource($request),
        ]);
    }

    /**
     * Approve a leave request.
     */
    public function approve(LeaveRequest $leaveRequest): JsonResponse
    {
        $request = $this->leaveService->approve($leaveRequest);

        return response()->json([
            'message' => 'Leave request approved successfully.',
            'data' => new LeaveRequestResource($request),
        ]);
    }

    /**
     * Reject a leave request.
     */
    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $leave = $this->leaveService->reject($leaveRequest, $validated['reason']);

        return response()->json([
            'message' => 'Leave request rejected.',
            'data' => new LeaveRequestResource($leave),
        ]);
    }

    /**
     * Cancel a leave request.
     */
    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $leave = $this->leaveService->cancel($leaveRequest, $validated['reason']);

        return response()->json([
            'message' => 'Leave request cancelled.',
            'data' => new LeaveRequestResource($leave),
        ]);
    }

    /**
     * Get employee leave balances.
     */
    public function balances(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $balances = $this->leaveService->getAllBalances($employee, $validated['year'] ?? null);

        return response()->json(['data' => $balances]);
    }

    /**
     * Initialize leave balances for a year.
     */
    public function initializeBalances(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $count = $this->leaveService->initializeYearBalances($validated['year']);

        return response()->json([
            'message' => "Initialized leave balances for {$count} employee-leave type combinations.",
        ]);
    }

    /**
     * Get leave summary.
     */
    public function summary(): JsonResponse
    {
        $summary = $this->leaveService->getOrganizationSummary();

        return response()->json(['data' => $summary]);
    }
}
