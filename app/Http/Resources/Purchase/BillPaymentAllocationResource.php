<?php

declare(strict_types=1);

namespace App\Http\Resources\Purchase;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillPaymentAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_made_id' => $this->payment_made_id,
            'bill_id' => $this->bill_id,

            // Bill summary when loaded
            'bill' => $this->whenLoaded('bill', fn() => [
                'id' => $this->bill->id,
                'bill_number' => $this->bill->bill_number,
                'supplier_invoice_number' => $this->bill->supplier_invoice_number,
                'bill_date' => $this->bill->bill_date?->toDateString(),
                'due_date' => $this->bill->due_date?->toDateString(),
                'total' => (float) $this->bill->total,
                'amount_due' => (float) $this->bill->amount_due,
                'status' => $this->bill->status,
            ]),

            // Payment summary when loaded
            'payment' => $this->whenLoaded('payment', fn() => [
                'id' => $this->payment->id,
                'payment_number' => $this->payment->payment_number,
                'payment_date' => $this->payment->payment_date?->toDateString(),
                'amount' => (float) $this->payment->amount,
                'status' => $this->payment->status,
            ]),

            // Amounts
            'amount' => (float) $this->amount,
            'base_amount' => (float) $this->base_amount,

            // Timestamps
            'allocated_at' => $this->allocated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
