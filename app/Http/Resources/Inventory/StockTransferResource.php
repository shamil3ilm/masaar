<?php

declare(strict_types=1);

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'transfer_number' => $this->transfer_number,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'expected_arrival_date' => $this->expected_arrival_date?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,

            'from_warehouse' => $this->whenLoaded('fromWarehouse', fn() => [
                'id' => $this->fromWarehouse->id,
                'name' => $this->fromWarehouse->name,
                'code' => $this->fromWarehouse->code,
            ]),

            'to_warehouse' => $this->whenLoaded('toWarehouse', fn() => [
                'id' => $this->toWarehouse->id,
                'name' => $this->toWarehouse->name,
                'code' => $this->toWarehouse->code,
            ]),

            'lines' => $this->whenLoaded('lines', fn() =>
                $this->lines->map(fn($line) => [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product?->name,
                    'product_sku' => $line->product?->sku,
                    'variant_id' => $line->variant_id,
                    'variant_name' => $line->variant?->name,
                    'quantity_sent' => $line->quantity_sent,
                    'quantity_received' => $line->quantity_received,
                    'unit_cost' => $line->unit_cost,
                    'total_value' => $line->getTotalValue(),
                    'is_fully_received' => $line->isFullyReceived(),
                    'has_discrepancy' => !$line->isFullyReceived(),
                    'notes' => $line->notes,
                ])
            ),

            'totals' => [
                'line_count' => $this->lines->count(),
                'total_quantity_sent' => $this->getTotalQuantity(),
                'total_value' => $this->getTotalValue(),
            ],

            'is_overdue' => $this->status === 'in_transit' &&
                $this->expected_arrival_date &&
                $this->expected_arrival_date->isPast(),

            'shipped_at' => $this->shipped_at?->toISOString(),
            'shipped_by' => $this->whenLoaded('shipper', fn() => [
                'id' => $this->shipper->id,
                'name' => $this->shipper->name,
            ]),

            'received_at' => $this->received_at?->toISOString(),
            'received_by' => $this->whenLoaded('receiver', fn() => [
                'id' => $this->receiver->id,
                'name' => $this->receiver->name,
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
