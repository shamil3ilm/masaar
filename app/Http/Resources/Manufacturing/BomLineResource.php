<?php

declare(strict_types=1);

namespace App\Http\Resources\Manufacturing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bom_template_id' => $this->bom_template_id,

            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn() => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
                'purchase_price' => (float) $this->product->purchase_price,
            ]),
            'variant_id' => $this->variant_id,
            'variant' => $this->whenLoaded('variant', fn() => [
                'id' => $this->variant->id,
                'name' => $this->variant->name,
                'sku' => $this->variant->sku,
            ]),

            'description' => $this->description,
            'display_description' => $this->getDisplayDescription(),
            'quantity' => (float) $this->quantity,

            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn() => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'symbol' => $this->unit->symbol,
            ]),

            'unit_cost' => $this->unit_cost ? (float) $this->unit_cost : null,
            'wastage_percentage' => (float) $this->wastage_percentage,
            'is_critical' => $this->is_critical,

            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn() => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),

            'line_order' => $this->line_order,

            // Calculated values
            'adjusted_quantity' => $this->getAdjustedQuantity(),
            'line_cost' => $this->getLineCost(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
