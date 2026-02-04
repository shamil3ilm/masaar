<?php

declare(strict_types=1);

namespace App\Http\Resources\Purchase;

use App\Http\Resources\Sales\ContactResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'order_number' => $this->order_number,
            'status' => $this->status,

            // Supplier info
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier_name,
            'supplier_email' => $this->supplier_email,
            'supplier_address' => $this->supplier_address,
            'supplier' => new ContactResource($this->whenLoaded('supplier')),

            // Warehouse & Delivery
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn() => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
                'code' => $this->warehouse->code,
            ]),
            'delivery_address' => $this->delivery_address,

            // Dates
            'order_date' => $this->order_date?->toDateString(),
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'delivery_date' => $this->delivery_date?->toDateString(),

            // Currency
            'currency_code' => $this->currency_code,
            'exchange_rate' => (float) $this->exchange_rate,

            // Amounts
            'subtotal' => (float) $this->subtotal,
            'discount_type' => $this->discount_type,
            'discount_value' => (float) $this->discount_value,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'total' => (float) $this->total,

            // Status info
            'is_editable' => $this->isEditable(),
            'can_be_received' => $this->canBeReceived(),
            'can_be_billed' => $this->canBeBilled(),
            'receiving_progress' => $this->getReceivingProgress(),

            // Lines & Related
            'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')),
            'bills' => BillResource::collection($this->whenLoaded('bills')),

            // Metadata
            'notes' => $this->notes,
            'terms_and_conditions' => $this->terms_and_conditions,
            'reference' => $this->reference,
            'branch_id' => $this->branch_id,
            'version' => $this->version,
            'requested_by' => $this->requested_by,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
