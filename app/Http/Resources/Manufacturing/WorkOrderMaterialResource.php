<?php

declare(strict_types=1);

namespace App\Http\Resources\Manufacturing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'bom_line_id' => $this->bom_line_id,

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

            'description' => $this->description,
            'display_description' => $this->getDisplayDescription(),

            // Quantities
            'required_quantity' => (float) $this->required_quantity,
            'issued_quantity' => (float) $this->issued_quantity,
            'consumed_quantity' => (float) $this->consumed_quantity,
            'returned_quantity' => (float) $this->returned_quantity,
            'wastage_quantity' => (float) $this->wastage_quantity,

            // Calculated
            'pending_issue_quantity' => $this->getPendingIssueQuantity(),
            'available_quantity' => $this->getAvailableQuantity(),
            'is_fully_issued' => $this->isFullyIssued(),
            'is_fully_consumed' => $this->isFullyConsumed(),
            'consumption_percentage' => $this->getConsumptionPercentage(),
            'wastage_percentage' => $this->getWastagePercentage(),

            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn() => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'symbol' => $this->unit->symbol,
            ]),

            // Cost
            'unit_cost' => (float) $this->unit_cost,
            'total_cost' => (float) $this->total_cost,

            // Warehouse
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn() => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),

            'line_order' => $this->line_order,

            // Transactions
            'transactions' => MaterialTransactionResource::collection($this->whenLoaded('transactions')),
            'transactions_count' => $this->whenCounted('transactions'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
