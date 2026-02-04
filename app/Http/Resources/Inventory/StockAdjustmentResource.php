<?php

declare(strict_types=1);

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'adjustment_number' => $this->adjustment_number,
            'adjustment_date' => $this->adjustment_date?->toDateString(),
            'reason' => $this->reason,
            'reason_label' => $this->getReasonLabel(),
            'notes' => $this->notes,
            'status' => $this->status,

            'warehouse' => $this->whenLoaded('warehouse', fn() => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
                'code' => $this->warehouse->code,
            ]),

            'lines' => $this->whenLoaded('lines', fn() =>
                $this->lines->map(fn($line) => [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product?->name,
                    'product_sku' => $line->product?->sku,
                    'variant_id' => $line->variant_id,
                    'variant_name' => $line->variant?->name,
                    'location_id' => $line->location_id,
                    'system_quantity' => $line->system_quantity,
                    'actual_quantity' => $line->actual_quantity,
                    'difference' => $line->difference,
                    'unit_cost' => $line->unit_cost,
                    'total_cost' => $line->total_cost,
                    'notes' => $line->notes,
                ])
            ),

            'totals' => [
                'line_count' => $this->lines->count(),
                'net_quantity_change' => $this->getNetQuantityChange(),
                'total_value' => $this->getTotalValue(),
            ],

            'posted_at' => $this->posted_at?->toISOString(),
            'posted_by' => $this->whenLoaded('poster', fn() => [
                'id' => $this->poster->id,
                'name' => $this->poster->name,
            ]),

            'created_by' => $this->whenLoaded('creator', fn() => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
