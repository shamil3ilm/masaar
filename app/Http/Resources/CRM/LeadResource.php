<?php

declare(strict_types=1);

namespace App\Http\Resources\CRM;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'lead_number' => $this->lead_number,
            'title' => $this->title,
            'status' => $this->status,
            'lead_type' => $this->lead_type,

            // Company info
            'company_name' => $this->company_name,
            'industry' => $this->industry,
            'website' => $this->website,
            'employee_count' => $this->employee_count,
            'annual_revenue' => (float) $this->annual_revenue,

            // Contact info
            'contact_name' => $this->contact_name,
            'contact_title' => $this->contact_title,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'display_name' => $this->getDisplayName(),

            // Address
            'address' => [
                'line_1' => $this->address_line_1,
                'line_2' => $this->address_line_2,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country_code' => $this->country_code,
            ],

            // Source
            'lead_source_id' => $this->lead_source_id,
            'lead_source' => $this->whenLoaded('leadSource', fn() => [
                'id' => $this->leadSource->id,
                'name' => $this->leadSource->name,
            ]),
            'source_details' => $this->source_details,

            // Assignment
            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn() => [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ]),
            'branch_id' => $this->branch_id,

            // Scoring
            'lead_score' => $this->lead_score,
            'rating' => $this->rating,
            'estimated_value' => (float) $this->estimated_value,
            'currency_code' => $this->currency_code,

            // Status flags
            'is_new' => $this->isNew(),
            'is_open' => $this->isOpen(),
            'is_converted' => $this->isConverted(),
            'is_lost' => $this->isLost(),
            'can_be_converted' => $this->canBeConverted(),
            'age_days' => $this->getAge(),

            // Conversion info
            'converted_contact_id' => $this->converted_contact_id,
            'converted_opportunity_id' => $this->converted_opportunity_id,
            'converted_at' => $this->converted_at?->toIso8601String(),

            // Activities
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),

            // Metadata
            'lost_reason' => $this->lost_reason,
            'description' => $this->description,
            'notes' => $this->notes,
            'tags' => $this->tags,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
