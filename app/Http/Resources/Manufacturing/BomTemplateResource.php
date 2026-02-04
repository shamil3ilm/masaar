<?php

declare(strict_types=1);

namespace App\Http\Resources\Manufacturing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'bom_number' => $this->bom_number,
            'name' => $this->name,
            'description' => $this->description,

            // Finished product
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
            'output_quantity' => (float) $this->output_quantity,
            'output_unit_id' => $this->output_unit_id,
            'output_unit' => $this->whenLoaded('outputUnit', fn() => [
                'id' => $this->outputUnit->id,
                'name' => $this->outputUnit->name,
                'symbol' => $this->outputUnit->symbol,
            ]),

            // Defaults
            'default_warehouse_id' => $this->default_warehouse_id,
            'default_warehouse' => $this->whenLoaded('defaultWarehouse', fn() => [
                'id' => $this->defaultWarehouse->id,
                'name' => $this->defaultWarehouse->name,
            ]),
            'estimated_hours' => $this->estimated_hours,
            'estimated_labor_cost' => $this->estimated_labor_cost ? (float) $this->estimated_labor_cost : null,
            'overhead_cost' => (float) $this->overhead_cost,

            // Status
            'status' => $this->status,
            'effective_from' => $this->effective_from?->format('Y-m-d'),
            'effective_to' => $this->effective_to?->format('Y-m-d'),
            'is_effective' => $this->isEffective(),

            'version' => $this->version,
            'notes' => $this->notes,

            // Lines and operations
            'lines' => BomLineResource::collection($this->whenLoaded('lines')),
            'operations' => BomOperationResource::collection($this->whenLoaded('operations')),

            // Counts
            'lines_count' => $this->whenCounted('lines'),
            'operations_count' => $this->whenCounted('operations'),
            'work_orders_count' => $this->whenCounted('workOrders'),

            // Costs (when calculated)
            'total_material_cost' => $this->when(
                $this->relationLoaded('lines'),
                fn() => $this->calculateMaterialCost()
            ),
            'total_labor_cost' => $this->when(
                $this->relationLoaded('operations'),
                fn() => $this->calculateLaborCost()
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
