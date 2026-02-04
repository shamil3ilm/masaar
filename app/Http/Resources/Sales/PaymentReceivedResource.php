<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentReceivedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'payment_number' => $this->payment_number,
            'payment_date' => $this->payment_date?->toDateString(),
            'status' => $this->status,

            'customer' => $this->whenLoaded('customer', fn() => [
                'id' => $this->customer->id,
                'name' => $this->customer->getDisplayName(),
                'email' => $this->customer->email,
            ]),

            'bank_account' => $this->whenLoaded('bankAccount', fn() => [
                'id' => $this->bankAccount->id,
                'name' => $this->bankAccount->account_name,
                'number' => $this->bankAccount->account_number,
            ]),

            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->getPaymentMethodLabel(),

            'amount' => $this->amount,
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'base_amount' => $this->base_amount,

            'allocated_amount' => $this->getAllocatedAmount(),
            'unallocated_amount' => $this->getUnallocatedAmount(),
            'is_fully_allocated' => $this->isFullyAllocated(),

            'allocations' => $this->whenLoaded('allocations', fn() =>
                $this->allocations->map(fn($alloc) => [
                    'id' => $alloc->id,
                    'invoice_id' => $alloc->invoice_id,
                    'invoice_number' => $alloc->invoice?->invoice_number,
                    'amount' => $alloc->amount,
                    'allocated_at' => $alloc->allocated_at?->toISOString(),
                ])
            ),

            'reference' => $this->reference,
            'notes' => $this->notes,

            'journal_entry_id' => $this->journal_entry_id,

            'created_by' => $this->whenLoaded('creator', fn() => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
