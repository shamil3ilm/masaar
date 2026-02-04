<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Manufacturing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Manufacturing\ProductionLogResource;
use App\Http\Resources\Manufacturing\WorkOrderResource;
use App\Models\Manufacturing\BomTemplate;
use App\Models\Manufacturing\WorkOrder;
use App\Models\Manufacturing\WorkOrderOperation;
use App\Services\Manufacturing\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkOrderController extends Controller
{
    public function __construct(
        private WorkOrderService $workOrderService
    ) {}

    /**
     * List work orders with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = WorkOrder::with(['product', 'bomTemplate', 'assignedTo', 'sourceWarehouse', 'targetWarehouse'])
            ->withCount(['materials', 'operations', 'productionLogs'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->priority, fn($q, $priority) => $q->where('priority', $priority))
            ->when($request->product_id, fn($q, $id) => $q->forProduct($id))
            ->when($request->assigned_to, fn($q, $id) => $q->assignedTo($id))
            ->when($request->branch_id, fn($q, $id) => $q->where('branch_id', $id))
            ->when($request->overdue === 'true', fn($q) => $q->overdue())
            ->when($request->active === 'true', fn($q) => $q->active())
            ->when($request->start_date, fn($q, $date) => $q->where('planned_start_date', '>=', $date))
            ->when($request->end_date, fn($q, $date) => $q->where('planned_end_date', '<=', $date))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('work_order_number', 'like', "%{$search}%")
                        ->orWhereHas('product', fn($p) => $p->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy($request->sort_by ?? 'created_at', $request->sort_order ?? 'desc');

        $workOrders = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return WorkOrderResource::collection($workOrders);
    }

    /**
     * Store a new work order.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bom_template_id' => 'required|exists:bom_templates,id',
            'planned_quantity' => 'required|numeric|min:0.0001',
            'planned_start_date' => 'required|date',
            'planned_end_date' => 'required|date|after_or_equal:planned_start_date',
            'branch_id' => 'nullable|exists:branches,id',
            'source_warehouse_id' => 'nullable|exists:warehouses,id',
            'target_warehouse_id' => 'nullable|exists:warehouses,id',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'assigned_to' => 'nullable|exists:users,id',
            'supervisor_id' => 'nullable|exists:users,id',
            'sales_order_id' => 'nullable|integer',
            'sales_order_line_id' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        $bom = BomTemplate::findOrFail($validated['bom_template_id']);

        $workOrder = $this->workOrderService->create($bom, $validated);

        return response()->json([
            'message' => 'Work order created successfully.',
            'data' => new WorkOrderResource($workOrder),
        ], 201);
    }

    /**
     * Show a specific work order.
     */
    public function show(WorkOrder $workOrder): WorkOrderResource
    {
        return new WorkOrderResource(
            $workOrder->load([
                'product',
                'variant',
                'bomTemplate',
                'unit',
                'sourceWarehouse',
                'targetWarehouse',
                'assignedTo',
                'supervisor',
                'branch',
                'materials.product',
                'materials.unit',
                'materials.warehouse',
                'operations.assignedTo',
                'operations.completedBy',
                'productionLogs.loggedBy',
                'createdBy',
            ])
        );
    }

    /**
     * Update a work order.
     */
    public function update(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'planned_start_date' => 'sometimes|date',
            'planned_end_date' => 'sometimes|date|after_or_equal:planned_start_date',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'assigned_to' => 'nullable|exists:users,id',
            'supervisor_id' => 'nullable|exists:users,id',
            'source_warehouse_id' => 'nullable|exists:warehouses,id',
            'target_warehouse_id' => 'nullable|exists:warehouses,id',
            'notes' => 'nullable|string',
        ]);

        $workOrder = $this->workOrderService->update($workOrder, $validated);

        return response()->json([
            'message' => 'Work order updated successfully.',
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Delete a draft work order.
     */
    public function destroy(WorkOrder $workOrder): JsonResponse
    {
        if (!$workOrder->isDraft()) {
            return response()->json([
                'message' => 'Only draft work orders can be deleted.',
            ], 422);
        }

        $workOrder->materials()->delete();
        $workOrder->operations()->delete();
        $workOrder->delete();

        return response()->json([
            'message' => 'Work order deleted successfully.',
        ]);
    }

    /**
     * Release work order for production.
     */
    public function release(WorkOrder $workOrder): JsonResponse
    {
        $workOrder = $this->workOrderService->release($workOrder);

        return response()->json([
            'message' => 'Work order released successfully.',
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Schedule a work order.
     */
    public function schedule(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'planned_start_date' => 'nullable|date',
            'planned_end_date' => 'nullable|date|after_or_equal:planned_start_date',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $workOrder = $this->workOrderService->schedule($workOrder, $validated);

        return response()->json([
            'message' => 'Work order scheduled successfully.',
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Start a work order.
     */
    public function start(WorkOrder $workOrder): JsonResponse
    {
        $workOrder = $this->workOrderService->start($workOrder);

        return response()->json([
            'message' => 'Work order started successfully.',
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Complete a work order.
     */
    public function complete(WorkOrder $workOrder): JsonResponse
    {
        $workOrder = $this->workOrderService->complete($workOrder);

        return response()->json([
            'message' => 'Work order completed successfully.',
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Cancel a work order.
     */
    public function cancel(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $workOrder = $this->workOrderService->cancel($workOrder, $validated['reason']);

        return response()->json([
            'message' => 'Work order cancelled successfully.',
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Issue materials to work order.
     */
    public function issueMaterials(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'issues' => 'required|array|min:1',
            'issues.*.work_order_material_id' => 'required|exists:work_order_materials,id',
            'issues.*.quantity' => 'required|numeric|min:0.0001',
            'issues.*.warehouse_id' => 'nullable|exists:warehouses,id',
            'issues.*.reference' => 'nullable|string|max:100',
            'issues.*.notes' => 'nullable|string',
        ]);

        $workOrder = $this->workOrderService->issueMaterials($workOrder, $validated['issues']);

        return response()->json([
            'message' => 'Materials issued successfully.',
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Return materials from work order.
     */
    public function returnMaterials(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'returns' => 'required|array|min:1',
            'returns.*.work_order_material_id' => 'required|exists:work_order_materials,id',
            'returns.*.quantity' => 'required|numeric|min:0.0001',
            'returns.*.warehouse_id' => 'nullable|exists:warehouses,id',
            'returns.*.reference' => 'nullable|string|max:100',
            'returns.*.notes' => 'nullable|string',
        ]);

        $workOrder = $this->workOrderService->returnMaterials($workOrder, $validated['returns']);

        return response()->json([
            'message' => 'Materials returned successfully.',
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Record material consumption.
     */
    public function consumeMaterials(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'consumptions' => 'required|array|min:1',
            'consumptions.*.work_order_material_id' => 'required|exists:work_order_materials,id',
            'consumptions.*.quantity' => 'required|numeric|min:0.0001',
            'consumptions.*.wastage_quantity' => 'nullable|numeric|min:0',
            'consumptions.*.wastage_reason' => 'nullable|string|max:500',
        ]);

        $workOrder = $this->workOrderService->consumeMaterials($workOrder, $validated['consumptions']);

        return response()->json([
            'message' => 'Material consumption recorded successfully.',
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Record production output.
     */
    public function recordProduction(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'quantity_produced' => 'required|numeric|min:0.0001',
            'quantity_rejected' => 'nullable|numeric|min:0',
            'rejection_reason' => 'nullable|string|max:500',
            'batch_number' => 'nullable|string|max:100',
            'lot_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'logged_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $log = $this->workOrderService->recordProduction($workOrder, $validated);

        return response()->json([
            'message' => 'Production recorded successfully.',
            'data' => new ProductionLogResource($log),
        ]);
    }

    /**
     * Start an operation.
     */
    public function startOperation(WorkOrder $workOrder, WorkOrderOperation $operation): JsonResponse
    {
        if ($operation->work_order_id !== $workOrder->id) {
            return response()->json([
                'message' => 'Operation does not belong to this work order.',
            ], 422);
        }

        if (!$operation->canBeStarted()) {
            return response()->json([
                'message' => 'Operation cannot be started in its current status.',
            ], 422);
        }

        $operation->start();

        return response()->json([
            'message' => 'Operation started successfully.',
            'data' => new WorkOrderResource($workOrder->fresh(['operations'])),
        ]);
    }

    /**
     * Complete an operation.
     */
    public function completeOperation(Request $request, WorkOrder $workOrder, WorkOrderOperation $operation): JsonResponse
    {
        if ($operation->work_order_id !== $workOrder->id) {
            return response()->json([
                'message' => 'Operation does not belong to this work order.',
            ], 422);
        }

        if (!$operation->canBeCompleted()) {
            return response()->json([
                'message' => 'Operation cannot be completed in its current status.',
            ], 422);
        }

        $validated = $request->validate([
            'actual_minutes' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $operation->complete(
            $validated['actual_minutes'] ?? null,
            $validated['notes'] ?? null
        );

        return response()->json([
            'message' => 'Operation completed successfully.',
            'data' => new WorkOrderResource($workOrder->fresh(['operations'])),
        ]);
    }

    /**
     * Get work order statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $filters = $request->only(['branch_id', 'start_date', 'end_date']);

        $statistics = $this->workOrderService->getStatistics($filters);

        return response()->json([
            'data' => $statistics,
        ]);
    }

    /**
     * Get production schedule.
     */
    public function schedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $schedule = $this->workOrderService->getProductionSchedule(
            $validated['start_date'],
            $validated['end_date']
        );

        return response()->json([
            'data' => $schedule,
        ]);
    }
}
