<?php

declare(strict_types=1);

namespace App\Http\Resources\HR;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayslipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'payslip_number' => $this->payslip_number,
            'status' => $this->status,

            // Employee
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn() => [
                'id' => $this->employee->id,
                'name' => $this->employee->getDisplayName(),
                'employee_number' => $this->employee->employee_number,
            ]),

            // Period
            'payroll_period_id' => $this->payroll_period_id,
            'payroll_period' => $this->whenLoaded('payrollPeriod', fn() => [
                'id' => $this->payrollPeriod->id,
                'name' => $this->payrollPeriod->name,
            ]),

            // Dates
            'payment_date' => $this->payment_date?->toDateString(),

            // Days
            'total_working_days' => (float) $this->total_working_days,
            'days_worked' => (float) $this->days_worked,
            'days_on_leave' => (float) $this->days_on_leave,
            'unpaid_leave_days' => (float) $this->unpaid_leave_days,
            'overtime_hours' => (float) $this->overtime_hours,

            // Amounts
            'gross_earnings' => (float) $this->gross_earnings,
            'total_deductions' => (float) $this->total_deductions,
            'net_salary' => (float) $this->net_salary,
            'currency_code' => $this->currency_code,

            // Tax
            'taxable_income' => (float) $this->taxable_income,
            'tax_deducted' => (float) $this->tax_deducted,

            // Items
            'earnings' => $this->whenLoaded('items', fn() =>
                $this->earnings->map(fn($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'amount' => (float) $item->amount,
                    'ytd_amount' => (float) $item->ytd_amount,
                ])
            ),
            'deductions' => $this->whenLoaded('items', fn() =>
                $this->deductions->map(fn($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'amount' => (float) $item->amount,
                    'ytd_amount' => (float) $item->ytd_amount,
                ])
            ),

            // Status flags
            'is_editable' => $this->isEditable(),
            'is_paid' => $this->isPaid(),

            // Payment
            'payment_mode' => $this->payment_mode,
            'payment_reference' => $this->payment_reference,
            'paid_at' => $this->paid_at?->toIso8601String(),

            // Approval
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),

            // Metadata
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
