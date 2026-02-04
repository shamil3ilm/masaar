<?php

declare(strict_types=1);

namespace App\Http\Resources\Purchase;

use App\Http\Resources\Sales\ContactResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMadeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'payment_number' => $this->payment_number,
            'status' => $this->status,

            // Supplier info
            'supplier_id' => $this->supplier_id,
            'supplier' => new ContactResource($this->whenLoaded('supplier')),

            // Payment details
            'payment_date' => $this->payment_date?->toDateString(),
            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->getPaymentMethodLabel(),

            // Bank account
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => $this->whenLoaded('bankAccount', fn() => [
                'id' => $this->bankAccount->id,
                'name' => $this->bankAccount->name,
                'account_number' => $this->bankAccount->account_number,
            ]),

            // Currency
            'currency_code' => $this->currency_code,
            'exchange_rate' => (float) $this->exchange_rate,

            // Amounts
            'amount' => (float) $this->amount,
            'base_amount' => (float) $this->base_amount,
            'allocated_amount' => $this->getAllocatedAmount(),
            'unallocated_amount' => $this->getUnallocatedAmount(),
            'is_fully_allocated' => $this->isFullyAllocated(),

            // Status info
            'is_editable' => $this->isEditable(),
            'is_completed' => $this->isCompleted(),

            // Allocations
            'allocations' => BillPaymentAllocationResource::collection($this->whenLoaded('allocations')),

            // Accounting
            'journal_entry_id' => $this->journal_entry_id,
            'journal_entry' => $this->whenLoaded('journalEntry'),

            // Metadata
            'reference' => $this->reference,
            'notes' => $this->notes,
            'branch_id' => $this->branch_id,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
