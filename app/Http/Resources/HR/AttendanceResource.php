<?php

declare(strict_types=1);

namespace App\Http\Resources\HR;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendance_date' => $this->attendance_date?->toDateString(),
            'status' => $this->status,

            // Employee
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn() => [
                'id' => $this->employee->id,
                'name' => $this->employee->getDisplayName(),
                'employee_number' => $this->employee->employee_number,
            ]),

            // Work schedule
            'work_schedule_id' => $this->work_schedule_id,
            'work_schedule' => $this->whenLoaded('workSchedule', fn() => [
                'id' => $this->workSchedule->id,
                'name' => $this->workSchedule->name,
            ]),

            // Check in/out
            'check_in' => $this->check_in?->toIso8601String(),
            'check_out' => $this->check_out?->toIso8601String(),
            'break_start' => $this->break_start?->toIso8601String(),
            'break_end' => $this->break_end?->toIso8601String(),

            // Hours
            'working_hours' => (float) $this->working_hours,
            'overtime_hours' => (float) $this->overtime_hours,
            'break_hours' => (float) $this->break_hours,
            'late_minutes' => $this->late_minutes,
            'early_leaving_minutes' => $this->early_leaving_minutes,

            // Source
            'source' => $this->source,
            'device_id' => $this->device_id,

            // Location
            'check_in_location' => $this->check_in_latitude ? [
                'latitude' => (float) $this->check_in_latitude,
                'longitude' => (float) $this->check_in_longitude,
            ] : null,
            'check_out_location' => $this->check_out_latitude ? [
                'latitude' => (float) $this->check_out_latitude,
                'longitude' => (float) $this->check_out_longitude,
            ] : null,

            // Regularization
            'is_regularized' => $this->is_regularized,
            'regularization_reason' => $this->regularization_reason,

            // Status flags
            'is_present' => $this->isPresent(),
            'is_absent' => $this->isAbsent(),
            'has_incomplete_entry' => $this->hasIncompleteEntry(),
            'needs_regularization' => $this->needsRegularization(),

            // Metadata
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
