<?php

declare(strict_types=1);

namespace App\Http\Resources\CRM;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpportunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'opportunity_number' => $this->opportunity_number,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,

            // Related
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', fn() => [
                'id' => $this->contact->id,
                'name' => $this->contact->getDisplayName(),
            ]),
            'lead_id' => $this->lead_id,
            'account_name' => $this->account_name,

            // Pipeline
            'pipeline_stage_id' => $this->pipeline_stage_id,
            'pipeline_stage' => $this->whenLoaded('pipelineStage', fn() => [
                'id' => $this->pipelineStage->id,
                'name' => $this->pipelineStage->name,
                'color' => $this->pipelineStage->color,
                'probability' => $this->pipelineStage->probability,
            ]),
            'probability' => $this->probability,

            // Value
            'amount' => (float) $this->amount,
            'currency_code' => $this->currency_code,
            'expected_revenue' => (float) $this->expected_revenue,

            // Dates
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'actual_close_date' => $this->actual_close_date?->toDateString(),
            'days_open' => $this->getDaysOpen(),
            'days_until_close' => $this->getDaysUntilClose(),

            // Status flags
            'is_open' => $this->isOpen(),
            'is_won' => $this->isWon(),
            'is_lost' => $this->isLost(),
            'is_closed' => $this->isClosed(),
            'is_overdue' => $this->isOverdue(),

            // Assignment
            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn() => [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ]),
            'branch_id' => $this->branch_id,

            // Source
            'lead_source_id' => $this->lead_source_id,
            'lead_source' => $this->whenLoaded('leadSource', fn() => [
                'id' => $this->leadSource->id,
                'name' => $this->leadSource->name,
            ]),

            // Reasons
            'won_reason' => $this->won_reason,
            'lost_reason' => $this->lost_reason,

            // Activities
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),

            // Metadata
            'notes' => $this->notes,
            'tags' => $this->tags,
            'competitors' => $this->competitors,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
