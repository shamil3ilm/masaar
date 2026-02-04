<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'address' => [
                'line_1' => $this->address_line_1,
                'line_2' => $this->address_line_2,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country_code' => $this->country_code,
            ],
            'phone' => $this->phone,
            'email' => $this->email,
            'tax_number' => $this->tax_number,
            'compliance_status' => $this->compliance_status,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'is_user_default' => $this->whenPivotLoaded('user_branches', fn () => (bool) $this->pivot->is_default),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
