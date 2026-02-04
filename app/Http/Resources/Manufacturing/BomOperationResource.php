<?php

declare(strict_types=1);

namespace App\Http\Resources\Manufacturing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bom_template_id' => $this->bom_template_id,

            'name' => $this->name,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'sequence' => $this->sequence,

            'estimated_minutes' => $this->estimated_minutes,
            'estimated_hours' => $this->getEstimatedHours(),
            'labor_cost_per_hour' => $this->labor_cost_per_hour ? (float) $this->labor_cost_per_hour : null,
            'labor_cost' => $this->calculateLaborCost(),

            'workstation' => $this->workstation,
            'required_skills' => $this->required_skills,
            'is_subcontracted' => $this->is_subcontracted,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
