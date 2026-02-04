<?php

declare(strict_types=1);

namespace App\Http\Resources\Manufacturing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'work_order_material_id' => $this->work_order_material_id,

            'transaction_type' => $this->transaction_type,
            'type_label' => $this->getTypeLabel(),
            'is_issue' => $this->isIssue(),
            'is_return' => $this->isReturn(),
            'is_wastage' => $this->isWastage(),

            'transaction_datetime' => $this->transaction_datetime?->toIso8601String(),
            'quantity' => (float) $this->quantity,
            'signed_quantity' => $this->getSignedQuantity(),
            'unit_cost' => (float) $this->unit_cost,
            'total_value' => $this->getTotalValue(),

            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn() => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),

            'stock_movement_id' => $this->stock_movement_id,
            'reference' => $this->reference,
            'notes' => $this->notes,

            // Audit
            'processed_by' => $this->processed_by,
            'processor' => $this->whenLoaded('processedBy', fn() => [
                'id' => $this->processedBy->id,
                'name' => $this->processedBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
