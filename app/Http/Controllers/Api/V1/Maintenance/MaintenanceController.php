<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\Maintenance\Equipment;
use App\Models\Maintenance\EquipmentCategory;
use App\Models\Maintenance\FunctionalLocation;
use App\Models\Maintenance\MaintenanceOrder;
use App\Models\Maintenance\MaintenancePlan;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function __construct(
        private MaintenanceService $maintenanceService
    ) {}

    // =========================================================================
    // Functional Locations
    // =========================================================================

    public function functionalLocationIndex(Request $request): JsonResponse
    {
        $query = FunctionalLocation::query()
            ->with('parent')
            ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
            ->when($request->input('location_type'), fn ($q, $t) => $q->where('location_type', $t))
            ->when($request->boolean('roots_only'), fn ($q) => $q->roots())
            ->when($request->input('parent_id'), fn ($q, $id) => $q->where('parent_id', $id))
            ->orderBy('name');

        $locations = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($locations);
    }

    public function functionalLocationStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id'     => 'nullable|integer|exists:functional_locations,id',
            'code'          => 'required|string|max:50',
            'name'          => 'required|string|max:200',
            'description'   => 'nullable|string',
            'location_type' => 'required|in:' . implode(',', FunctionalLocation::LOCATION_TYPES),
            'branch_id'     => 'nullable|integer|exists:branches,id',
            'address'       => 'nullable|string|max:500',
            'is_active'     => 'nullable|boolean',
        ]);

        $location = FunctionalLocation::create($data);

        return $this->created($location, 'Functional location created successfully.');
    }

    public function functionalLocationShow(FunctionalLocation $functionalLocation): JsonResponse
    {
        $functionalLocation->load(['parent', 'children', 'equipment']);

        return $this->success($functionalLocation);
    }

    public function functionalLocationUpdate(Request $request, FunctionalLocation $functionalLocation): JsonResponse
    {
        $data = $request->validate([
            'parent_id'     => 'nullable|integer|exists:functional_locations,id',
            'code'          => 'sometimes|required|string|max:50',
            'name'          => 'sometimes|required|string|max:200',
            'description'   => 'nullable|string',
            'location_type' => 'sometimes|required|in:' . implode(',', FunctionalLocation::LOCATION_TYPES),
            'branch_id'     => 'nullable|integer|exists:branches,id',
            'address'       => 'nullable|string|max:500',
            'is_active'     => 'nullable|boolean',
        ]);

        $functionalLocation->update($data);

        return $this->success($functionalLocation->fresh(), 'Functional location updated successfully.');
    }

    public function functionalLocationDestroy(FunctionalLocation $functionalLocation): JsonResponse
    {
        if ($functionalLocation->children()->count() > 0) {
            return $this->error('Cannot delete a location that has child locations.', 'HAS_CHILDREN', 422);
        }

        if ($functionalLocation->equipment()->count() > 0) {
            return $this->error('Cannot delete a location with assigned equipment.', 'HAS_EQUIPMENT', 422);
        }

        $functionalLocation->delete();

        return $this->success(null, 'Functional location deleted successfully.');
    }

    // =========================================================================
    // Equipment Categories
    // =========================================================================

    public function categoryIndex(Request $request): JsonResponse
    {
        $categories = EquipmentCategory::query()
            ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->withCount('equipment')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($categories);
    }

    public function categoryStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        $category = EquipmentCategory::create($data);

        return $this->created($category, 'Equipment category created successfully.');
    }

    public function categoryShow(EquipmentCategory $equipmentCategory): JsonResponse
    {
        return $this->success($equipmentCategory->load('equipment'));
    }

    public function categoryUpdate(Request $request, EquipmentCategory $equipmentCategory): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
        ]);

        $equipmentCategory->update($data);

        return $this->success($equipmentCategory->fresh(), 'Category updated successfully.');
    }

    public function categoryDestroy(EquipmentCategory $equipmentCategory): JsonResponse
    {
        if ($equipmentCategory->equipment()->count() > 0) {
            return $this->error('Cannot delete a category that has equipment assigned to it.', 'HAS_EQUIPMENT', 422);
        }

        $equipmentCategory->delete();

        return $this->success(null, 'Equipment category deleted successfully.');
    }

    // =========================================================================
    // Equipment
    // =========================================================================

    public function equipmentIndex(Request $request): JsonResponse
    {
        $query = Equipment::query()
            ->with(['category', 'functionalLocation'])
            ->when($request->input('search'), function ($q, $s) {
                $q->where(function ($inner) use ($s) {
                    $inner->where('name', 'like', "%{$s}%")
                          ->orWhere('equipment_number', 'like', "%{$s}%")
                          ->orWhere('serial_number', 'like', "%{$s}%");
                });
            })
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('equipment_category_id'), fn ($q, $id) => $q->where('equipment_category_id', $id))
            ->when($request->input('functional_location_id'), fn ($q, $id) => $q->where('functional_location_id', $id))
            ->orderBy('name');

        $equipment = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($equipment);
    }

    public function equipmentStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'functional_location_id' => 'nullable|integer|exists:functional_locations,id',
            'equipment_category_id'  => 'nullable|integer|exists:equipment_categories,id',
            'equipment_number'       => 'required|string|max:50',
            'name'                   => 'required|string|max:200',
            'description'            => 'nullable|string',
            'manufacturer'           => 'nullable|string|max:100',
            'model'                  => 'nullable|string|max:100',
            'serial_number'          => 'nullable|string|max:100',
            'acquisition_date'       => 'nullable|date',
            'acquisition_cost'       => 'nullable|numeric|min:0',
            'warranty_expiry'        => 'nullable|date',
            'status'                 => 'nullable|in:' . implode(',', Equipment::STATUSES),
            'notes'                  => 'nullable|string',
        ]);

        try {
            $equipment = $this->maintenanceService->createEquipment($data, auth()->id());

            return $this->created($equipment->load(['category', 'functionalLocation']), 'Equipment created successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    public function equipmentShow(Equipment $equipment): JsonResponse
    {
        $equipment->load(['category', 'functionalLocation', 'maintenancePlans', 'creator']);

        return $this->success($equipment);
    }

    public function equipmentUpdate(Request $request, Equipment $equipment): JsonResponse
    {
        $data = $request->validate([
            'functional_location_id' => 'nullable|integer|exists:functional_locations,id',
            'equipment_category_id'  => 'nullable|integer|exists:equipment_categories,id',
            'name'                   => 'sometimes|required|string|max:200',
            'description'            => 'nullable|string',
            'manufacturer'           => 'nullable|string|max:100',
            'model'                  => 'nullable|string|max:100',
            'serial_number'          => 'nullable|string|max:100',
            'acquisition_date'       => 'nullable|date',
            'acquisition_cost'       => 'nullable|numeric|min:0',
            'warranty_expiry'        => 'nullable|date',
            'status'                 => 'nullable|in:' . implode(',', Equipment::STATUSES),
            'notes'                  => 'nullable|string',
        ]);

        $equipment = $this->maintenanceService->updateEquipment($equipment, $data);

        return $this->success($equipment, 'Equipment updated successfully.');
    }

    public function equipmentDestroy(Equipment $equipment): JsonResponse
    {
        if ($equipment->maintenanceOrders()->whereIn('status', [MaintenanceOrder::STATUS_OPEN, MaintenanceOrder::STATUS_IN_PROGRESS])->exists()) {
            return $this->error('Cannot delete equipment with open or in-progress maintenance orders.', 'HAS_OPEN_ORDERS', 422);
        }

        $equipment->delete();

        return $this->success(null, 'Equipment deleted successfully.');
    }

    public function equipmentDueSoon(Request $request): JsonResponse
    {
        $days  = $request->integer('days', 7);
        $orgId = auth()->user()->organization_id;

        $equipment = $this->maintenanceService->getDueEquipment($orgId, $days);

        return $this->success($equipment);
    }

    // =========================================================================
    // Maintenance Plans
    // =========================================================================

    public function planIndex(Request $request): JsonResponse
    {
        $plans = MaintenancePlan::query()
            ->with('equipment')
            ->when($request->input('equipment_id'), fn ($q, $id) => $q->where('equipment_id', $id))
            ->when($request->input('is_active') !== null, fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->input('maintenance_type'), fn ($q, $t) => $q->where('maintenance_type', $t))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($plans);
    }

    public function planStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'equipment_id'             => 'required|integer|exists:equipment,id',
            'name'                     => 'required|string|max:200',
            'maintenance_type'         => 'required|in:' . implode(',', MaintenancePlan::MAINTENANCE_TYPES),
            'frequency_type'           => 'required|in:' . implode(',', MaintenancePlan::FREQUENCY_TYPES),
            'frequency_value'          => 'required|integer|min:1',
            'estimated_duration_hours' => 'nullable|numeric|min:0',
            'description'              => 'nullable|string',
            'tasks'                    => 'nullable|array',
            'tasks.*.description'      => 'required_with:tasks|string',
            'tasks.*.is_safety_critical' => 'nullable|boolean',
            'is_active'                => 'nullable|boolean',
        ]);

        try {
            $plan = $this->maintenanceService->createMaintenancePlan($data, auth()->id());

            return $this->created($plan->load('equipment'), 'Maintenance plan created successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    public function planUpdate(Request $request, MaintenancePlan $maintenancePlan): JsonResponse
    {
        $data = $request->validate([
            'name'                     => 'sometimes|required|string|max:200',
            'maintenance_type'         => 'sometimes|required|in:' . implode(',', MaintenancePlan::MAINTENANCE_TYPES),
            'frequency_type'           => 'sometimes|required|in:' . implode(',', MaintenancePlan::FREQUENCY_TYPES),
            'frequency_value'          => 'sometimes|required|integer|min:1',
            'estimated_duration_hours' => 'nullable|numeric|min:0',
            'description'              => 'nullable|string',
            'tasks'                    => 'nullable|array',
            'tasks.*.description'      => 'required_with:tasks|string',
            'tasks.*.is_safety_critical' => 'nullable|boolean',
            'is_active'                => 'nullable|boolean',
        ]);

        $plan = $this->maintenanceService->updateMaintenancePlan($maintenancePlan, $data);

        return $this->success($plan, 'Maintenance plan updated successfully.');
    }

    public function planToggleActive(MaintenancePlan $maintenancePlan): JsonResponse
    {
        $maintenancePlan->update(['is_active' => !$maintenancePlan->is_active]);

        $state = $maintenancePlan->is_active ? 'activated' : 'deactivated';

        return $this->success($maintenancePlan->fresh(), "Maintenance plan {$state} successfully.");
    }

    public function planGenerateOrder(MaintenancePlan $maintenancePlan): JsonResponse
    {
        try {
            $order = $this->maintenanceService->generateOrderFromPlan($maintenancePlan, auth()->id());

            return $this->created($order, 'Maintenance order generated from plan successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    // =========================================================================
    // Maintenance Orders
    // =========================================================================

    public function orderIndex(Request $request): JsonResponse
    {
        $query = MaintenanceOrder::query()
            ->with(['equipment', 'assignee', 'plan'])
            ->when($request->input('search'), function ($q, $s) {
                $q->where(function ($inner) use ($s) {
                    $inner->where('order_number', 'like', "%{$s}%")
                          ->orWhere('description', 'like', "%{$s}%");
                });
            })
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when($request->input('order_type'), fn ($q, $t) => $q->where('order_type', $t))
            ->when($request->input('equipment_id'), fn ($q, $id) => $q->where('equipment_id', $id))
            ->when($request->input('assigned_to'), fn ($q, $id) => $q->where('assigned_to', $id))
            ->orderByRaw("FIELD(priority, 'critical','high','medium','low')")
            ->orderBy('created_at', 'desc');

        $orders = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($orders);
    }

    public function orderStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'equipment_id'     => 'required|integer|exists:equipment,id',
            'order_type'       => 'required|in:' . implode(',', MaintenanceOrder::ORDER_TYPES),
            'priority'         => 'nullable|in:' . implode(',', MaintenanceOrder::PRIORITIES),
            'description'      => 'required|string',
            'scheduled_start'  => 'nullable|date',
            'scheduled_end'    => 'nullable|date|after_or_equal:scheduled_start',
            'assigned_to'      => 'nullable|integer|exists:users,id',
            'estimated_cost'   => 'nullable|numeric|min:0',
            'tasks'            => 'nullable|array',
            'tasks.*.task_description'   => 'required_with:tasks|string',
            'tasks.*.is_safety_critical' => 'nullable|boolean',
            'tasks.*.sort_order'         => 'nullable|integer',
            'parts'            => 'nullable|array',
            'parts.*.product_id'         => 'nullable|integer|exists:products,id',
            'parts.*.description'        => 'required_with:parts|string',
            'parts.*.quantity_required'  => 'nullable|numeric|min:0',
            'parts.*.unit_cost'          => 'nullable|numeric|min:0',
        ]);

        try {
            $tasks = $data['tasks'] ?? [];
            $parts = $data['parts'] ?? [];
            unset($data['tasks'], $data['parts']);

            $order = $this->maintenanceService->createMaintenanceOrder($data, $tasks, $parts, auth()->id());

            return $this->created($order, 'Maintenance order created successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    public function orderShow(MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        $maintenanceOrder->load(['equipment.category', 'equipment.functionalLocation', 'tasks', 'parts.product', 'assignee', 'plan', 'creator']);

        return $this->success($maintenanceOrder);
    }

    public function orderUpdate(Request $request, MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        if (in_array($maintenanceOrder->status, [MaintenanceOrder::STATUS_COMPLETED, MaintenanceOrder::STATUS_CANCELLED], true)) {
            return $this->error('Cannot update a completed or cancelled order.', 'ORDER_CLOSED', 422);
        }

        $data = $request->validate([
            'priority'        => 'nullable|in:' . implode(',', MaintenanceOrder::PRIORITIES),
            'description'     => 'nullable|string',
            'scheduled_start' => 'nullable|date',
            'scheduled_end'   => 'nullable|date|after_or_equal:scheduled_start',
            'assigned_to'     => 'nullable|integer|exists:users,id',
            'estimated_cost'  => 'nullable|numeric|min:0',
            'status'          => 'nullable|in:' . implode(',', [MaintenanceOrder::STATUS_ON_HOLD]),
        ]);

        $maintenanceOrder->update($data);

        return $this->success($maintenanceOrder->fresh(), 'Maintenance order updated successfully.');
    }

    public function orderDestroy(MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        if (!in_array($maintenanceOrder->status, [MaintenanceOrder::STATUS_OPEN, MaintenanceOrder::STATUS_CANCELLED], true)) {
            return $this->error('Only open or cancelled orders can be deleted.', 'ORDER_NOT_DELETABLE', 422);
        }

        $maintenanceOrder->delete();

        return $this->success(null, 'Maintenance order deleted successfully.');
    }

    public function orderStart(Request $request, MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->maintenanceService->startOrder($maintenanceOrder, auth()->id()),
            'Maintenance order started successfully.',
            'INVALID_STATE'
        );
    }

    public function orderCompleteTask(Request $request, MaintenanceOrder $maintenanceOrder, int $taskId): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'nullable|string',
        ]);

        return $this->tryAction(
            fn() => $this->maintenanceService->completeTask($maintenanceOrder, $taskId, $data['notes'] ?? '', auth()->id()),
            'Task completed successfully.',
            'INVALID_STATE'
        );
    }

    public function orderComplete(Request $request, MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        $data = $request->validate([
            'resolution_notes' => 'nullable|string',
            'actual_cost'      => 'nullable|numeric|min:0',
            'downtime_hours'   => 'nullable|numeric|min:0',
        ]);

        return $this->tryAction(
            fn() => $this->maintenanceService->completeOrder($maintenanceOrder, $data, auth()->id()),
            'Maintenance order completed successfully.',
            'INVALID_STATE'
        );
    }

    public function orderCancel(Request $request, MaintenanceOrder $maintenanceOrder): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->maintenanceService->cancelOrder($maintenanceOrder, auth()->id()),
            'Maintenance order cancelled successfully.',
            'INVALID_STATE'
        );
    }

    // =========================================================================
    // Statistics
    // =========================================================================

    public function stats(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $orgId = auth()->user()->organization_id;
        $stats = $this->maintenanceService->getMaintenanceStats(
            $orgId,
            $request->input('from'),
            $request->input('to')
        );

        return $this->success($stats);
    }
}
