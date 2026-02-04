<?php

declare(strict_types=1);

namespace App\Http\Resources\Manufacturing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'work_order_number' => $this->work_order_number,

            // BOM Reference
            'bom_template_id' => $this->bom_template_id,
            'bom_template' => $this->whenLoaded('bomTemplate', fn() => [
                'id' => $this->bomTemplate->id,
                'bom_number' => $this->bomTemplate->bom_number,
                'name' => $this->bomTemplate->name,
            ]),

            // Sales Order Reference
            'sales_order_id' => $this->sales_order_id,
            'sales_order_line_id' => $this->sales_order_line_id,

            // Product
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn() => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'variant_id' => $this->variant_id,
            'variant' => $this->whenLoaded('variant', fn() => [
                'id' => $this->variant->id,
                'name' => $this->variant->name,
                'sku' => $this->variant->sku,
            ]),

            // Quantities
            'planned_quantity' => (float) $this->planned_quantity,
            'produced_quantity' => (float) $this->produced_quantity,
            'rejected_quantity' => (float) $this->rejected_quantity,
            'remaining_quantity' => $this->getRemainingQuantity(),
            'good_quantity' => $this->getGoodQuantity(),
            'completion_percentage' => $this->getCompletionPercentage(),
            'rejection_rate' => $this->getRejectionRate(),

            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn() => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'symbol' => $this->unit->symbol,
            ]),

            // Dates
            'planned_start_date' => $this->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $this->planned_end_date?->format('Y-m-d'),
            'actual_start_datetime' => $this->actual_start_datetime?->toIso8601String(),
            'actual_end_datetime' => $this->actual_end_datetime?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),

            // Warehouses
            'source_warehouse_id' => $this->source_warehouse_id,
            'source_warehouse' => $this->whenLoaded('sourceWarehouse', fn() => [
                'id' => $this->sourceWarehouse->id,
                'name' => $this->sourceWarehouse->name,
            ]),
            'target_warehouse_id' => $this->target_warehouse_id,
            'target_warehouse' => $this->whenLoaded('targetWarehouse', fn() => [
                'id' => $this->targetWarehouse->id,
                'name' => $this->targetWarehouse->name,
            ]),

            // Estimated costs
            'estimated_material_cost' => (float) $this->estimated_material_cost,
            'estimated_labor_cost' => (float) $this->estimated_labor_cost,
            'estimated_overhead_cost' => (float) $this->estimated_overhead_cost,
            'total_estimated_cost' => $this->getTotalEstimatedCost(),

            // Actual costs
            'actual_material_cost' => (float) $this->actual_material_cost,
            'actual_labor_cost' => (float) $this->actual_labor_cost,
            'actual_overhead_cost' => (float) $this->actual_overhead_cost,
            'total_actual_cost' => $this->getTotalActualCost(),

            // Cost analysis
            'cost_variance' => $this->getCostVariance(),
            'unit_cost' => $this->getUnitCost(),

            // Status
            'status' => $this->status,
            'priority' => $this->priority,
            'is_active' => $this->isActive(),

            // Assignment
            'assigned_to' => $this->assigned_to,
            'assigned_user' => $this->whenLoaded('assignedTo', fn() => [
                'id' => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ]),
            'supervisor_id' => $this->supervisor_id,
            'supervisor' => $this->whenLoaded('supervisor', fn() => [
                'id' => $this->supervisor->id,
                'name' => $this->supervisor->name,
            ]),

            // Branch
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn() => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),

            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,

            // Related data
            'materials' => WorkOrderMaterialResource::collection($this->whenLoaded('materials')),
            'operations' => WorkOrderOperationResource::collection($this->whenLoaded('operations')),
            'production_logs' => ProductionLogResource::collection($this->whenLoaded('productionLogs')),

            // Counts
            'materials_count' => $this->whenCounted('materials'),
            'operations_count' => $this->whenCounted('operations'),
            'production_logs_count' => $this->whenCounted('productionLogs'),

            // Progress
            'operations_progress' => $this->when(
                $this->relationLoaded('operations'),
                fn() => $this->getOperationsProgress()
            ),
            'materials_consumption' => $this->when(
                $this->relationLoaded('materials'),
                fn() => $this->getMaterialsConsumptionSummary()
            ),

            // Audit
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('createdBy', fn() => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
