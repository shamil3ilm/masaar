<?php

declare(strict_types=1);

namespace App\Http\Resources\CRM;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'activity_type' => $this->activity_type,
            'activity_type_label' => $this->getActivityTypeLabel(),
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,

            // Related
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,

            // Timing
            'start_datetime' => $this->start_datetime?->toIso8601String(),
            'end_datetime' => $this->end_datetime?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'is_all_day' => $this->is_all_day,
            'completed_at' => $this->completed_at?->toIso8601String(),

            // Call specific
            'call_direction' => $this->call_direction,
            'call_result' => $this->call_result,

            // Meeting specific
            'location' => $this->location,
            'meeting_link' => $this->meeting_link,
            'attendees' => $this->attendees,

            // Assignment
            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn() => [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ]),

            // Reminder
            'reminder_datetime' => $this->reminder_datetime?->toIso8601String(),
            'reminder_sent' => $this->reminder_sent,

            // Status flags
            'is_planned' => $this->isPlanned(),
            'is_completed' => $this->isCompleted(),
            'is_cancelled' => $this->isCancelled(),
            'is_overdue' => $this->isOverdue(),

            // Outcome
            'outcome' => $this->outcome,
            'notes' => $this->notes,

            // Metadata
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
