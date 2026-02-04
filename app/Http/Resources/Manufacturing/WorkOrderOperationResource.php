<?php

declare(strict_types=1);

namespace App\Http\Resources\Manufacturing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'bom_operation_id' => $this->bom_operation_id,

            'name' => $this->name,
            'instructions' => $this->instructions,
            'sequence' => $this->sequence,

            // Time
            'estimated_minutes' => $this->estimated_minutes,
            'actual_minutes' => $this->actual_minutes,
            'estimated_hours' => $this->getEstimatedHours(),
            'actual_hours' => $this->getActualHours(),

            // Time variance
            'time_variance' => $this->getTimeVariance(),
            'time_variance_percentage' => $this->getTimeVariancePercentage(),
            'current_duration' => $this->getCurrentDuration(),

            // Dates
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            // Status
            'status' => $this->status,
            'is_pending' => $this->isPending(),
            'is_in_progress' => $this->isInProgress(),
            'is_completed' => $this->isCompleted(),
            'is_skipped' => $this->isSkipped(),
            'can_be_started' => $this->canBeStarted(),
            'can_be_completed' => $this->canBeCompleted(),

            // Assignment
            'assigned_to' => $this->assigned_to,
            'assigned_user' => $this->whenLoaded('assignedTo', fn() => [
                'id' => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ]),
            'completed_by' => $this->completed_by,
            'completed_by_user' => $this->whenLoaded('completedBy', fn() => [
                'id' => $this->completedBy->id,
                'name' => $this->completedBy->name,
            ]),

            'notes' => $this->notes,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
