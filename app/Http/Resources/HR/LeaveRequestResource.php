<?php

declare(strict_types=1);

namespace App\Http\Resources\HR;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status,

            // Employee
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn() => [
                'id' => $this->employee->id,
                'name' => $this->employee->getDisplayName(),
                'employee_number' => $this->employee->employee_number,
            ]),

            // Leave type
            'leave_type_id' => $this->leave_type_id,
            'leave_type' => $this->whenLoaded('leaveType', fn() => [
                'id' => $this->leaveType->id,
                'name' => $this->leaveType->name,
                'code' => $this->leaveType->code,
                'is_paid' => $this->leaveType->is_paid,
            ]),

            // Period
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'total_days' => (float) $this->total_days,
            'is_half_day' => $this->is_half_day,
            'half_day_type' => $this->half_day_type,

            // Details
            'reason' => $this->reason,
            'contact_during_leave' => $this->contact_during_leave,
            'address_during_leave' => $this->address_during_leave,
            'attachment_path' => $this->attachment_path,

            // Status info
            'is_editable' => $this->isEditable(),
            'can_be_cancelled' => $this->canBeCancelled(),

            // Approval
            'approved_by' => $this->approved_by,
            'approver' => $this->whenLoaded('approver', fn() => [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ]),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,

            // Metadata
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
