<?php

declare(strict_types=1);

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'is_active' => $this->is_active,

            'full_path' => $this->when(
                !$request->routeIs('*.index'),
                fn() => $this->getFullPath()
            ),

            'parent' => $this->whenLoaded('parent', fn() => [
                'id' => $this->parent->id,
                'name' => $this->parent->name,
                'slug' => $this->parent->slug,
            ]),

            'children' => $this->whenLoaded('children', fn() =>
                CategoryResource::collection($this->children)
            ),

            'all_children' => $this->whenLoaded('allChildren', fn() =>
                CategoryResource::collection($this->allChildren)
            ),

            'products_count' => $this->whenCounted('products'),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
