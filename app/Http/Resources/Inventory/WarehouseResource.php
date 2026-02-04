<?php

declare(strict_types=1);

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'phone' => $this->phone,
            'email' => $this->email,

            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'allow_negative_stock' => $this->allow_negative_stock,

            'branch' => $this->whenLoaded('branch', fn() => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),

            'manager' => $this->whenLoaded('manager', fn() => [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
                'email' => $this->manager->email,
            ]),

            'locations' => $this->whenLoaded('locations', fn() =>
                $this->locations->map(fn($l) => [
                    'id' => $l->id,
                    'code' => $l->code,
                    'name' => $l->name,
                    'type' => $l->type,
                    'parent_id' => $l->parent_id,
                    'is_active' => $l->is_active,
                ])
            ),

            'stock_count' => $this->when(
                !$request->routeIs('*.index'),
                fn() => $this->stockLevels()->count()
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
