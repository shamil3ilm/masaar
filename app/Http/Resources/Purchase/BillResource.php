<?php

declare(strict_types=1);

namespace App\Http\Resources\Purchase;

use App\Http\Resources\Sales\ContactResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'bill_number' => $this->bill_number,
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'bill_type' => $this->bill_type,
            'status' => $this->status,

            // Supplier info
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier_name,
            'supplier_tax_number' => $this->supplier_tax_number,
            'supplier_address' => $this->supplier_address,
            'supplier' => new ContactResource($this->whenLoaded('supplier')),

            // Related documents
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => new PurchaseOrderResource($this->whenLoaded('purchaseOrder')),
            'original_bill_id' => $this->original_bill_id,

            // Dates
            'bill_date' => $this->bill_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'received_date' => $this->received_date?->toDateString(),

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
            'base_total' => (float) $this->base_total,
            'amount_paid' => (float) $this->amount_paid,
            'amount_due' => (float) $this->amount_due,

            // Tax info
            'place_of_supply' => $this->place_of_supply,
            'is_reverse_charge' => $this->is_reverse_charge,

            // Status info
            'is_editable' => $this->isEditable(),
            'is_paid' => $this->isPaid(),
            'is_overdue' => $this->isOverdue(),
            'days_past_due' => $this->getDaysPastDue(),

            // Lines
            'lines' => BillLineResource::collection($this->whenLoaded('lines')),
            'payment_allocations' => BillPaymentAllocationResource::collection($this->whenLoaded('paymentAllocations')),

            // Accounting
            'journal_entry_id' => $this->journal_entry_id,
            'journal_entry' => $this->whenLoaded('journalEntry'),

            // Metadata
            'notes' => $this->notes,
            'branch_id' => $this->branch_id,
            'version' => $this->version,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
