<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\EmployeeExit;
use App\Models\HR\ExitClearanceItem;
use App\Services\HR\ExitManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExitManagementController extends Controller
{
    public function __construct(
        private readonly ExitManagementService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginated = $this->service->list($request->only([
            'status', 'employee_id', 'exit_type', 'per_page',
        ]));

        return $this->paginated($paginated);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'          => 'required|integer|exists:employees,id',
            'exit_type'            => 'required|in:resignation,termination,retirement,contract_end,death',
            'resignation_date'     => 'nullable|date',
            'last_working_date'    => 'nullable|date',
            'notice_period_days'   => 'nullable|integer|min:0',
            'notice_period_waived' => 'nullable|boolean',
            'exit_reason'          => 'nullable|string',
        ]);

        $exit = $this->service->initiate($validated);

        return $this->created($exit->load(['employee', 'initiator']), 'Employee exit initiated.');
    }

    public function show(string $id): JsonResponse
    {
        $exit = EmployeeExit::with(['employee', 'initiator', 'approver', 'clearanceItems.department', 'clearanceItems.responsiblePerson'])
            ->findOrFail($id);

        return $this->success($exit);
    }

    public function approve(string $id): JsonResponse
    {
        $exit = EmployeeExit::findOrFail($id);

        try {
            $exit = $this->service->approve($exit, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'INVALID_STATUS', 422);
        }

        return $this->success($exit->load(['employee', 'approver']), 'Exit approved. Employee is now in notice period.');
    }

    public function startClearance(string $id): JsonResponse
    {
        $exit = EmployeeExit::findOrFail($id);

        try {
            $exit = $this->service->startClearance($exit);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'INVALID_STATUS', 422);
        }

        return $this->success($exit->load('clearanceItems'), 'Clearance process started.');
    }

    public function clearItem(string $id, string $itemId): JsonResponse
    {
        $exit = EmployeeExit::findOrFail($id);
        $item = ExitClearanceItem::where('employee_exit_id', $exit->id)->findOrFail($itemId);

        $validated = request()->validate([
            'remarks' => 'nullable|string',
        ]);

        return $this->tryAction(
            fn() => $this->service->clearItem($item, $validated['remarks'] ?? null),
            'Clearance item marked as cleared.',
            'INVALID_STATUS'
        );
    }

    public function completeClearance(string $id): JsonResponse
    {
        $exit = EmployeeExit::findOrFail($id);

        return $this->tryAction(
            fn() => $this->service->completeClearance($exit)->load('clearanceItems'),
            'Clearance completed successfully.',
            'INVALID_STATUS'
        );
    }

    public function settle(Request $request, string $id): JsonResponse
    {
        $exit = EmployeeExit::findOrFail($id);

        $validated = $request->validate([
            'final_settlement_amount' => 'nullable|numeric|min:0',
            'settlement_date'         => 'nullable|date',
            'eosb_amount'             => 'nullable|numeric|min:0',
            'leave_encashment_amount' => 'nullable|numeric|min:0',
        ]);

        return $this->tryAction(
            fn() => $this->service->settle($exit, $validated)->load('employee'),
            'Final settlement recorded.',
            'INVALID_STATUS'
        );
    }

    public function close(string $id): JsonResponse
    {
        $exit = EmployeeExit::findOrFail($id);

        return $this->tryAction(
            fn() => $this->service->close($exit),
            'Exit record closed.',
            'INVALID_STATUS'
        );
    }
}
