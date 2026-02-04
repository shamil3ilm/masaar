<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'invoice_number' => $this->invoice_number,
            'invoice_type' => $this->invoice_type,
            'status' => $this->status,

            'customer' => $this->whenLoaded('customer', fn() => [
                'id' => $this->customer->id,
                'name' => $this->customer->getDisplayName(),
                'email' => $this->customer->email,
                'tax_number' => $this->customer->tax_number,
            ]),

            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_tax_number' => $this->customer_tax_number,
            'billing_address' => $this->billing_address,
            'shipping_address' => $this->shipping_address,

            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'days_past_due' => $this->getDaysPastDue(),

            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,

            'subtotal' => $this->subtotal,
            'discount' => [
                'type' => $this->discount_type,
                'value' => $this->discount_value,
                'amount' => $this->discount_amount,
            ],
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'base_total' => $this->base_total,
            'amount_paid' => $this->amount_paid,
            'amount_due' => $this->amount_due,

            'lines' => $this->whenLoaded('lines', fn() =>
                $this->lines->map(fn($line) => [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'product' => $line->product ? [
                        'id' => $line->product->id,
                        'sku' => $line->product->sku,
                        'name' => $line->product->name,
                    ] : null,
                    'variant_id' => $line->variant_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount' => [
                        'type' => $line->discount_type,
                        'value' => $line->discount_value,
                        'amount' => $line->discount_amount,
                    ],
                    'tax' => [
                        'category_id' => $line->tax_category_id,
                        'code' => $line->tax_code,
                        'rate' => $line->tax_rate,
                        'amount' => $line->tax_amount,
                    ],
                    'gst' => ($line->cgst_amount > 0 || $line->sgst_amount > 0 || $line->igst_amount > 0) ? [
                        'hsn_code' => $line->hsn_code,
                        'cgst' => ['rate' => $line->cgst_rate, 'amount' => $line->cgst_amount],
                        'sgst' => ['rate' => $line->sgst_rate, 'amount' => $line->sgst_amount],
                        'igst' => ['rate' => $line->igst_rate, 'amount' => $line->igst_amount],
                    ] : null,
                    'subtotal' => $line->subtotal,
                    'total' => $line->total,
                    'warehouse_id' => $line->warehouse_id,
                    'line_order' => $line->line_order,
                ])
            ),

            'compliance' => [
                'status' => $this->compliance_status,
                'uuid' => $this->compliance_uuid,
                'hash' => $this->compliance_hash,
                'qr_code' => $this->compliance_qr_code,
                'submitted_at' => $this->compliance_submitted_at?->toISOString(),
            ],

            'place_of_supply' => $this->place_of_supply,
            'is_reverse_charge' => $this->is_reverse_charge,

            'salesperson' => $this->whenLoaded('salesperson', fn() => [
                'id' => $this->salesperson->id,
                'name' => $this->salesperson->name,
            ]),

            'quotation_id' => $this->quotation_id,
            'sales_order_id' => $this->sales_order_id,
            'original_invoice_id' => $this->original_invoice_id,
            'journal_entry_id' => $this->journal_entry_id,

            'payment_allocations' => $this->whenLoaded('paymentAllocations', fn() =>
                $this->paymentAllocations->map(fn($alloc) => [
                    'id' => $alloc->id,
                    'payment_id' => $alloc->payment_received_id,
                    'payment_number' => $alloc->payment?->payment_number,
                    'amount' => $alloc->amount,
                    'allocated_at' => $alloc->allocated_at?->toISOString(),
                ])
            ),

            'notes' => $this->notes,
            'terms_and_conditions' => $this->terms_and_conditions,
            'reference' => $this->reference,

            'version' => $this->version,
            'is_editable' => $this->isEditable(),
            'is_overdue' => $this->isOverdue(),

            'created_by' => $this->whenLoaded('creator', fn() => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
