<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'slug' => $this->slug,
            'country_code' => $this->country_code,
            'tax_scheme' => $this->tax_scheme,
            'tax_number' => $this->tax_number,
            'base_currency' => $this->base_currency,
            'fiscal_year_start' => [
                'month' => $this->fiscal_year_start_month,
                'day' => $this->fiscal_year_start_day,
            ],
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address' => [
                'line_1' => $this->address_line_1,
                'line_2' => $this->address_line_2,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
            ],
            'logo_url' => $this->logo_url,
            'is_active' => $this->is_active,
            'requires_compliance' => $this->requiresCompliance(),
            'tax_details' => $this->getTaxSchemeDetails(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
