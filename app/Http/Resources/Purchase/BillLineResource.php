<?php

declare(strict_types=1);

namespace App\Http\Resources\Purchase;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bill_id' => $this->bill_id,

            // Product info
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn() => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'variant_id' => $this->variant_id,
            'description' => $this->description,

            // Quantities
            'quantity' => (float) $this->quantity,
            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn() => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'symbol' => $this->unit->symbol,
            ]),

            // Pricing
            'unit_price' => (float) $this->unit_price,
            'discount_type' => $this->discount_type,
            'discount_value' => (float) $this->discount_value,
            'discount_amount' => (float) $this->discount_amount,

            // Tax
            'tax_category_id' => $this->tax_category_id,
            'tax_category' => $this->whenLoaded('taxCategory', fn() => [
                'id' => $this->taxCategory->id,
                'name' => $this->taxCategory->name,
                'code' => $this->taxCategory->code,
            ]),
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'tax_code' => $this->tax_code,

            // GST split (India)
            'cgst_rate' => (float) $this->cgst_rate,
            'cgst_amount' => (float) $this->cgst_amount,
            'sgst_rate' => (float) $this->sgst_rate,
            'sgst_amount' => (float) $this->sgst_amount,
            'igst_rate' => (float) $this->igst_rate,
            'igst_amount' => (float) $this->igst_amount,
            'hsn_code' => $this->hsn_code,

            // Totals
            'subtotal' => (float) $this->subtotal,
            'total' => (float) $this->total,

            // Other
            'account_id' => $this->account_id,
            'warehouse_id' => $this->warehouse_id,
            'line_order' => $this->line_order,
        ];
    }
}
