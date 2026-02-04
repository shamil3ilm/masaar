<?php

declare(strict_types=1);

namespace App\Http\Resources\HR;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,

            // Dates
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'payment_date' => $this->payment_date?->toDateString(),

            // Status flags
            'is_open' => $this->isOpen(),
            'is_processed' => $this->isProcessed(),
            'is_closed' => $this->isClosed(),
            'can_be_processed' => $this->canBeProcessed(),
            'can_be_closed' => $this->canBeClosed(),

            // Stats
            'working_days_count' => $this->getWorkingDaysCount(),
            'employee_count' => $this->getEmployeeCount(),
            'total_payroll' => $this->getTotalPayroll(),

            // Processing info
            'processed_by' => $this->processed_by,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'closed_by' => $this->closed_by,
            'closed_at' => $this->closed_at?->toIso8601String(),

            // Payslips
            'payslips' => PayslipResource::collection($this->whenLoaded('payslips')),

            // Metadata
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
