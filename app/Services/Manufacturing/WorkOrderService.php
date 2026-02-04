<?php

declare(strict_types=1);

namespace App\Services\Manufacturing;

use App\Models\Manufacturing\BomTemplate;
use App\Models\Manufacturing\MaterialTransaction;
use App\Models\Manufacturing\ProductionLog;
use App\Models\Manufacturing\WorkOrder;
use App\Models\Manufacturing\WorkOrderMaterial;
use App\Models\Manufacturing\WorkOrderOperation;
use App\Services\Core\NumberGeneratorService;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    public function __construct(
        private NumberGeneratorService $numberGenerator,
        private StockService $stockService,
        private BomService $bomService
    ) {}

    /**
     * Create a work order from a BOM template.
     */
    public function create(BomTemplate $bom, array $data): WorkOrder
    {
        if (!$bom->isActive()) {
            throw new \InvalidArgumentException('BOM template must be active to create a work order.');
        }

        return DB::transaction(function () use ($bom, $data) {
            $quantity = (float) $data['planned_quantity'];

            // Calculate costs
            $costs = $bom->calculateTotalCost($quantity);

            $workOrder = WorkOrder::create([
                'organization_id' => auth()->user()->organization_id,
                'branch_id' => $data['branch_id'] ?? null,
                'work_order_number' => $this->numberGenerator->generate('WO'),
                'bom_template_id' => $bom->id,
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'sales_order_line_id' => $data['sales_order_line_id'] ?? null,
                'product_id' => $bom->product_id,
                'variant_id' => $bom->variant_id,
                'planned_quantity' => $quantity,
                'produced_quantity' => 0,
                'rejected_quantity' => 0,
                'unit_id' => $bom->output_unit_id,
                'planned_start_date' => $data['planned_start_date'],
                'planned_end_date' => $data['planned_end_date'],
                'source_warehouse_id' => $data['source_warehouse_id'] ?? $bom->default_warehouse_id,
                'target_warehouse_id' => $data['target_warehouse_id'] ?? $bom->default_warehouse_id,
                'estimated_material_cost' => $costs['material_cost'],
                'estimated_labor_cost' => $costs['labor_cost'],
                'estimated_overhead_cost' => $costs['overhead_cost'],
                'status' => WorkOrder::STATUS_DRAFT,
                'priority' => $data['priority'] ?? WorkOrder::PRIORITY_NORMAL,
                'assigned_to' => $data['assigned_to'] ?? null,
                'supervisor_id' => $data['supervisor_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Create work order materials from BOM lines
            $this->createMaterialsFromBom($workOrder, $bom, $quantity);

            // Create work order operations from BOM operations
            $this->createOperationsFromBom($workOrder, $bom);

            return $workOrder->fresh(['materials.product', 'operations', 'bomTemplate']);
        });
    }

    /**
     * Create work order materials from BOM lines.
     */
    protected function createMaterialsFromBom(WorkOrder $workOrder, BomTemplate $bom, float $quantity): void
    {
        $multiplier = $quantity / (float) $bom->output_quantity;

        foreach ($bom->lines as $line) {
            $requiredQuantity = $line->getAdjustedQuantity($multiplier);

            WorkOrderMaterial::create([
                'work_order_id' => $workOrder->id,
                'bom_line_id' => $line->id,
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'description' => $line->description,
                'required_quantity' => $requiredQuantity,
                'issued_quantity' => 0,
                'consumed_quantity' => 0,
                'returned_quantity' => 0,
                'wastage_quantity' => 0,
                'unit_id' => $line->unit_id,
                'unit_cost' => $line->unit_cost ?? $line->product->purchase_price ?? 0,
                'total_cost' => 0,
                'warehouse_id' => $line->warehouse_id ?? $workOrder->source_warehouse_id,
                'line_order' => $line->line_order,
            ]);
        }
    }

    /**
     * Create work order operations from BOM operations.
     */
    protected function createOperationsFromBom(WorkOrder $workOrder, BomTemplate $bom): void
    {
        foreach ($bom->operations as $operation) {
            WorkOrderOperation::create([
                'work_order_id' => $workOrder->id,
                'bom_operation_id' => $operation->id,
                'name' => $operation->name,
                'instructions' => $operation->instructions,
                'sequence' => $operation->sequence,
                'estimated_minutes' => $operation->estimated_minutes,
                'actual_minutes' => 0,
                'status' => WorkOrderOperation::STATUS_PENDING,
            ]);
        }
    }

    /**
     * Update a work order.
     */
    public function update(WorkOrder $workOrder, array $data): WorkOrder
    {
        if (!$workOrder->canBeEdited()) {
            throw new \InvalidArgumentException('Work order cannot be edited in its current status.');
        }

        $workOrder->update($data);

        return $workOrder->fresh();
    }

    /**
     * Release work order for production.
     */
    public function release(WorkOrder $workOrder): WorkOrder
    {
        if (!$workOrder->isDraft()) {
            throw new \InvalidArgumentException('Only draft work orders can be released.');
        }

        // Check material availability
        $availability = $this->bomService->checkAvailability(
            $workOrder->bomTemplate,
            (float) $workOrder->planned_quantity,
            $workOrder->source_warehouse_id
        );

        if ($availability['critical_shortage']) {
            throw new \InvalidArgumentException('Cannot release work order. Critical materials are not available.');
        }

        $workOrder->update(['status' => WorkOrder::STATUS_PENDING]);

        return $workOrder->fresh();
    }

    /**
     * Schedule a work order.
     */
    public function schedule(WorkOrder $workOrder, array $data): WorkOrder
    {
        if (!$workOrder->isPending()) {
            throw new \InvalidArgumentException('Only pending work orders can be scheduled.');
        }

        $workOrder->update([
            'status' => WorkOrder::STATUS_SCHEDULED,
            'planned_start_date' => $data['planned_start_date'] ?? $workOrder->planned_start_date,
            'planned_end_date' => $data['planned_end_date'] ?? $workOrder->planned_end_date,
            'assigned_to' => $data['assigned_to'] ?? $workOrder->assigned_to,
        ]);

        return $workOrder->fresh();
    }

    /**
     * Start a work order.
     */
    public function start(WorkOrder $workOrder): WorkOrder
    {
        if (!$workOrder->canBeStarted()) {
            throw new \InvalidArgumentException('Work order cannot be started in its current status.');
        }

        $workOrder->start();

        return $workOrder->fresh();
    }

    /**
     * Issue materials to work order.
     */
    public function issueMaterials(WorkOrder $workOrder, array $issues): WorkOrder
    {
        if (!$workOrder->isInProgress()) {
            throw new \InvalidArgumentException('Materials can only be issued to in-progress work orders.');
        }

        return DB::transaction(function () use ($workOrder, $issues) {
            foreach ($issues as $issue) {
                $material = WorkOrderMaterial::findOrFail($issue['work_order_material_id']);

                if ($material->work_order_id !== $workOrder->id) {
                    throw new \InvalidArgumentException('Material does not belong to this work order.');
                }

                $quantity = (float) $issue['quantity'];
                $warehouseId = $issue['warehouse_id'] ?? $material->warehouse_id;

                // Record stock movement (out)
                $this->stockService->recordMovement([
                    'product_id' => $material->product_id,
                    'warehouse_id' => $warehouseId,
                    'movement_type' => 'out',
                    'quantity' => $quantity,
                    'unit_cost' => $material->unit_cost,
                    'reference_type' => WorkOrder::class,
                    'reference_id' => $workOrder->id,
                    'notes' => "Material issue for WO: {$workOrder->work_order_number}",
                ]);

                // Record material transaction
                $transaction = MaterialTransaction::create([
                    'organization_id' => $workOrder->organization_id,
                    'work_order_id' => $workOrder->id,
                    'work_order_material_id' => $material->id,
                    'transaction_type' => MaterialTransaction::TYPE_ISSUE,
                    'transaction_datetime' => now(),
                    'quantity' => $quantity,
                    'unit_cost' => $material->unit_cost,
                    'warehouse_id' => $warehouseId,
                    'reference' => $issue['reference'] ?? null,
                    'notes' => $issue['notes'] ?? null,
                    'processed_by' => auth()->id(),
                ]);

                // Update material record
                $material->recordIssue($quantity);
            }

            $this->recalculateActualMaterialCost($workOrder);

            return $workOrder->fresh(['materials.product']);
        });
    }

    /**
     * Return materials from work order.
     */
    public function returnMaterials(WorkOrder $workOrder, array $returns): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $returns) {
            foreach ($returns as $return) {
                $material = WorkOrderMaterial::findOrFail($return['work_order_material_id']);

                if ($material->work_order_id !== $workOrder->id) {
                    throw new \InvalidArgumentException('Material does not belong to this work order.');
                }

                $quantity = (float) $return['quantity'];
                $warehouseId = $return['warehouse_id'] ?? $material->warehouse_id;

                // Check if we can return this much
                if ($quantity > $material->getAvailableQuantity()) {
                    throw new \InvalidArgumentException("Cannot return more than available quantity for {$material->product->name}.");
                }

                // Record stock movement (in)
                $this->stockService->recordMovement([
                    'product_id' => $material->product_id,
                    'warehouse_id' => $warehouseId,
                    'movement_type' => 'in',
                    'quantity' => $quantity,
                    'unit_cost' => $material->unit_cost,
                    'reference_type' => WorkOrder::class,
                    'reference_id' => $workOrder->id,
                    'notes' => "Material return from WO: {$workOrder->work_order_number}",
                ]);

                // Record material transaction
                MaterialTransaction::create([
                    'organization_id' => $workOrder->organization_id,
                    'work_order_id' => $workOrder->id,
                    'work_order_material_id' => $material->id,
                    'transaction_type' => MaterialTransaction::TYPE_RETURN,
                    'transaction_datetime' => now(),
                    'quantity' => $quantity,
                    'unit_cost' => $material->unit_cost,
                    'warehouse_id' => $warehouseId,
                    'reference' => $return['reference'] ?? null,
                    'notes' => $return['notes'] ?? null,
                    'processed_by' => auth()->id(),
                ]);

                // Update material record
                $material->recordReturn($quantity);
            }

            $this->recalculateActualMaterialCost($workOrder);

            return $workOrder->fresh(['materials.product']);
        });
    }

    /**
     * Record material consumption.
     */
    public function consumeMaterials(WorkOrder $workOrder, array $consumptions): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $consumptions) {
            foreach ($consumptions as $consumption) {
                $material = WorkOrderMaterial::findOrFail($consumption['work_order_material_id']);

                if ($material->work_order_id !== $workOrder->id) {
                    throw new \InvalidArgumentException('Material does not belong to this work order.');
                }

                $quantity = (float) $consumption['quantity'];
                $wastageQuantity = (float) ($consumption['wastage_quantity'] ?? 0);

                // Check if we have enough issued material
                $availableQuantity = $material->getAvailableQuantity();
                if (($quantity + $wastageQuantity) > $availableQuantity) {
                    throw new \InvalidArgumentException("Insufficient issued quantity for {$material->product->name}.");
                }

                // Record consumption
                $material->recordConsumption($quantity);

                // Record wastage if any
                if ($wastageQuantity > 0) {
                    $material->recordWastage($wastageQuantity);

                    MaterialTransaction::create([
                        'organization_id' => $workOrder->organization_id,
                        'work_order_id' => $workOrder->id,
                        'work_order_material_id' => $material->id,
                        'transaction_type' => MaterialTransaction::TYPE_WASTAGE,
                        'transaction_datetime' => now(),
                        'quantity' => $wastageQuantity,
                        'unit_cost' => $material->unit_cost,
                        'warehouse_id' => $material->warehouse_id,
                        'notes' => $consumption['wastage_reason'] ?? 'Material wastage',
                        'processed_by' => auth()->id(),
                    ]);
                }
            }

            $this->recalculateActualMaterialCost($workOrder);

            return $workOrder->fresh(['materials.product']);
        });
    }

    /**
     * Record production output.
     */
    public function recordProduction(WorkOrder $workOrder, array $data): ProductionLog
    {
        if (!$workOrder->isInProgress()) {
            throw new \InvalidArgumentException('Production can only be recorded for in-progress work orders.');
        }

        return DB::transaction(function () use ($workOrder, $data) {
            $quantityProduced = (float) $data['quantity_produced'];
            $quantityRejected = (float) ($data['quantity_rejected'] ?? 0);
            $goodQuantity = $quantityProduced - $quantityRejected;

            // Record stock movement for good quantity
            if ($goodQuantity > 0) {
                $this->stockService->recordMovement([
                    'product_id' => $workOrder->product_id,
                    'warehouse_id' => $workOrder->target_warehouse_id,
                    'movement_type' => 'in',
                    'quantity' => $goodQuantity,
                    'unit_cost' => $workOrder->getUnitCost(),
                    'reference_type' => WorkOrder::class,
                    'reference_id' => $workOrder->id,
                    'notes' => "Production from WO: {$workOrder->work_order_number}",
                ]);
            }

            // Create production log
            $log = ProductionLog::create([
                'organization_id' => $workOrder->organization_id,
                'work_order_id' => $workOrder->id,
                'logged_at' => $data['logged_at'] ?? now(),
                'quantity_produced' => $quantityProduced,
                'quantity_rejected' => $quantityRejected,
                'rejection_reason' => $data['rejection_reason'] ?? null,
                'quality_checked' => false,
                'batch_number' => $data['batch_number'] ?? null,
                'lot_number' => $data['lot_number'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'logged_by' => auth()->id(),
            ]);

            // Update work order quantities
            $workOrder->update([
                'produced_quantity' => bcadd((string) $workOrder->produced_quantity, (string) $quantityProduced, 4),
                'rejected_quantity' => bcadd((string) $workOrder->rejected_quantity, (string) $quantityRejected, 4),
            ]);

            return $log;
        });
    }

    /**
     * Complete a work order.
     */
    public function complete(WorkOrder $workOrder): WorkOrder
    {
        if (!$workOrder->canBeCompleted()) {
            throw new \InvalidArgumentException('Work order cannot be completed in its current status.');
        }

        return DB::transaction(function () use ($workOrder) {
            // Return any remaining issued but not consumed materials
            foreach ($workOrder->materials as $material) {
                $availableQuantity = $material->getAvailableQuantity();
                if ($availableQuantity > 0) {
                    $this->returnMaterials($workOrder, [[
                        'work_order_material_id' => $material->id,
                        'quantity' => $availableQuantity,
                        'notes' => 'Auto-return on work order completion',
                    ]]);
                }
            }

            // Recalculate actual costs
            $this->recalculateActualCosts($workOrder);

            // Complete all pending operations
            foreach ($workOrder->operations()->pending()->get() as $operation) {
                $operation->skip('Auto-skipped on work order completion');
            }

            $workOrder->complete();

            return $workOrder->fresh();
        });
    }

    /**
     * Cancel a work order.
     */
    public function cancel(WorkOrder $workOrder, string $reason): WorkOrder
    {
        if (!$workOrder->canBeCancelled()) {
            throw new \InvalidArgumentException('Work order cannot be cancelled in its current status.');
        }

        return DB::transaction(function () use ($workOrder, $reason) {
            // Return all issued materials if any
            foreach ($workOrder->materials as $material) {
                $availableQuantity = $material->getAvailableQuantity();
                if ($availableQuantity > 0) {
                    $this->returnMaterials($workOrder, [[
                        'work_order_material_id' => $material->id,
                        'quantity' => $availableQuantity,
                        'warehouse_id' => $material->warehouse_id,
                        'notes' => 'Material return due to work order cancellation',
                    ]]);
                }
            }

            $workOrder->cancel($reason);

            return $workOrder->fresh();
        });
    }

    /**
     * Recalculate actual material cost.
     */
    protected function recalculateActualMaterialCost(WorkOrder $workOrder): void
    {
        $totalCost = $workOrder->materials()->sum('total_cost');

        $workOrder->update(['actual_material_cost' => $totalCost]);
    }

    /**
     * Recalculate all actual costs.
     */
    protected function recalculateActualCosts(WorkOrder $workOrder): void
    {
        // Material cost
        $materialCost = $workOrder->materials()->sum('total_cost');

        // Labor cost (from operations)
        $laborCost = 0;
        foreach ($workOrder->operations as $operation) {
            if ($operation->bomOperation && $operation->actual_minutes > 0) {
                $hours = $operation->actual_minutes / 60;
                $laborCost = bcadd(
                    (string) $laborCost,
                    bcmul((string) $hours, (string) ($operation->bomOperation->labor_cost_per_hour ?? 0), 4),
                    4
                );
            }
        }

        // Overhead cost (proportional to produced quantity)
        $multiplier = (float) $workOrder->produced_quantity / (float) $workOrder->planned_quantity;
        $overheadCost = bcmul((string) $workOrder->estimated_overhead_cost, (string) $multiplier, 4);

        $workOrder->update([
            'actual_material_cost' => $materialCost,
            'actual_labor_cost' => $laborCost,
            'actual_overhead_cost' => $overheadCost,
        ]);
    }

    /**
     * Get work order statistics.
     */
    public function getStatistics(?array $filters = []): array
    {
        $query = WorkOrder::query();

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->startingBetween($filters['start_date'], $filters['end_date']);
        }

        $total = $query->count();
        $draft = (clone $query)->draft()->count();
        $pending = (clone $query)->pending()->count();
        $scheduled = (clone $query)->scheduled()->count();
        $inProgress = (clone $query)->inProgress()->count();
        $completed = (clone $query)->completed()->count();
        $cancelled = (clone $query)->cancelled()->count();
        $overdue = (clone $query)->overdue()->count();

        $totalPlanned = (clone $query)->sum('planned_quantity');
        $totalProduced = (clone $query)->sum('produced_quantity');
        $totalRejected = (clone $query)->sum('rejected_quantity');

        $avgCompletionRate = $totalPlanned > 0
            ? round(($totalProduced / $totalPlanned) * 100, 2)
            : 0;

        $avgRejectionRate = $totalProduced > 0
            ? round(($totalRejected / $totalProduced) * 100, 2)
            : 0;

        return [
            'total' => $total,
            'draft' => $draft,
            'pending' => $pending,
            'scheduled' => $scheduled,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'overdue' => $overdue,
            'total_planned_quantity' => (float) $totalPlanned,
            'total_produced_quantity' => (float) $totalProduced,
            'total_rejected_quantity' => (float) $totalRejected,
            'avg_completion_rate' => $avgCompletionRate,
            'avg_rejection_rate' => $avgRejectionRate,
        ];
    }

    /**
     * Get production schedule for date range.
     */
    public function getProductionSchedule($startDate, $endDate): array
    {
        $workOrders = WorkOrder::active()
            ->startingBetween($startDate, $endDate)
            ->with(['product', 'bomTemplate', 'assignedTo'])
            ->orderBy('planned_start_date')
            ->orderBy('priority', 'desc')
            ->get();

        return $workOrders->groupBy(fn($wo) => $wo->planned_start_date->format('Y-m-d'))
            ->map(fn($group) => $group->values())
            ->toArray();
    }
}
