<?php

declare(strict_types=1);

namespace App\Http\Resources\HR;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'employee_number' => $this->employee_number,

            // Name
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'display_name' => $this->getDisplayName(),
            'full_name' => $this->getFullName(),

            // Personal
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'age' => $this->getAge(),
            'gender' => $this->gender,
            'marital_status' => $this->marital_status,
            'nationality' => $this->nationality,
            'blood_group' => $this->blood_group,

            // Contact
            'email' => $this->email,
            'personal_email' => $this->personal_email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'emergency_contact' => [
                'name' => $this->emergency_contact_name,
                'phone' => $this->emergency_contact_phone,
                'relation' => $this->emergency_contact_relation,
            ],

            // Address
            'address' => [
                'line_1' => $this->address_line_1,
                'line_2' => $this->address_line_2,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country_code' => $this->country_code,
            ],

            // Employment
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn() => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'designation_id' => $this->designation_id,
            'designation' => $this->whenLoaded('designation', fn() => [
                'id' => $this->designation->id,
                'name' => $this->designation->name,
            ]),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn() => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'reporting_manager_id' => $this->reporting_manager_id,
            'reporting_manager' => $this->whenLoaded('reportingManager', fn() => [
                'id' => $this->reportingManager->id,
                'name' => $this->reportingManager->getDisplayName(),
            ]),

            // Dates
            'joining_date' => $this->joining_date?->toDateString(),
            'confirmation_date' => $this->confirmation_date?->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),
            'tenure_years' => $this->getTenureInYears(),
            'tenure_months' => $this->getTenureInMonths(),

            // Status
            'employment_type' => $this->employment_type,
            'employment_status' => $this->employment_status,
            'is_active' => $this->is_active,
            'is_on_probation' => $this->isOnProbation(),

            // Documents
            'national_id' => $this->national_id,
            'passport_number' => $this->passport_number,
            'passport_expiry' => $this->passport_expiry?->toDateString(),
            'visa_number' => $this->visa_number,
            'visa_expiry' => $this->visa_expiry?->toDateString(),
            'work_permit_number' => $this->work_permit_number,
            'work_permit_expiry' => $this->work_permit_expiry?->toDateString(),
            'tax_number' => $this->tax_number,

            // Bank
            'payment_mode' => $this->payment_mode,
            'bank_name' => $this->bank_name,
            'bank_account_number' => $this->bank_account_number,
            'bank_ifsc_code' => $this->bank_ifsc_code,
            'bank_iban' => $this->bank_iban,
            'currency_code' => $this->currency_code,

            // Related data
            'current_salary' => $this->whenLoaded('currentSalary'),
            'documents' => $this->whenLoaded('documents'),
            'qualifications' => $this->whenLoaded('qualifications'),
            'experiences' => $this->whenLoaded('experiences'),

            // Metadata
            'profile_photo_path' => $this->profile_photo_path,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
