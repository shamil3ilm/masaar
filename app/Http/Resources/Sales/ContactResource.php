<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'contact_type' => $this->contact_type,
            'company_name' => $this->company_name,
            'contact_name' => $this->contact_name,
            'display_name' => $this->getDisplayName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'website' => $this->website,
            'tax_number' => $this->tax_number,
            'tax_registration_name' => $this->tax_registration_name,
            'payment_terms' => $this->payment_terms,
            'credit_limit' => $this->credit_limit,
            'currency_code' => $this->currency_code,

            'billing_address' => [
                'line_1' => $this->billing_address_line_1,
                'line_2' => $this->billing_address_line_2,
                'city' => $this->billing_city,
                'state' => $this->billing_state,
                'postal_code' => $this->billing_postal_code,
                'country_code' => $this->billing_country_code,
                'formatted' => $this->getBillingAddress(),
            ],

            'shipping_address' => [
                'line_1' => $this->shipping_address_line_1,
                'line_2' => $this->shipping_address_line_2,
                'city' => $this->shipping_city,
                'state' => $this->shipping_state,
                'postal_code' => $this->shipping_postal_code,
                'country_code' => $this->shipping_country_code,
                'formatted' => $this->getShippingAddress(),
            ],

            'notes' => $this->notes,
            'is_active' => $this->is_active,

            'receivable_account' => $this->whenLoaded('receivableAccount', fn() => [
                'id' => $this->receivableAccount->id,
                'code' => $this->receivableAccount->code,
                'name' => $this->receivableAccount->name,
            ]),

            'payable_account' => $this->whenLoaded('payableAccount', fn() => [
                'id' => $this->payableAccount->id,
                'code' => $this->payableAccount->code,
                'name' => $this->payableAccount->name,
            ]),

            'outstanding_balance' => $this->when(
                $this->isCustomer(),
                fn() => $this->getOutstandingBalance()
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
