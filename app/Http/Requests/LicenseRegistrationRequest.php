<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domains\Licensing\Models\LicenseRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * License Registration Request.
 *
 * Validates incoming license registration data.
 */
class LicenseRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public registration
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'min:2', 'max:255'],
            'contact_name' => ['required', 'string', 'min:2', 'max:255'],
            'contact_email' => [
                'required',
                'email:rfc,dns',
                'max:255',
                'unique:license_registrations,contact_email',
            ],
            'vat_number' => [
                'nullable',
                'string',
                'size:15',
                'regex:/^3\d{14}$/', // Saudi VAT format: starts with 3, 15 digits
            ],
            'use_case_description' => ['required', 'string', 'min:20', 'max:2000'],
            'terms_accepted' => ['required', 'accepted'],
            'license_type' => [
                'nullable',
                Rule::in([
                    LicenseRegistration::TYPE_COMMERCIAL,
                    LicenseRegistration::TYPE_EDUCATIONAL,
                    LicenseRegistration::TYPE_NON_PROFIT,
                ]),
            ],
            'country_code' => ['nullable', 'string', 'size:2'],
            'industry' => ['nullable', 'string', 'max:100'],
            'company_size' => [
                'nullable',
                Rule::in([
                    LicenseRegistration::SIZE_SMALL,
                    LicenseRegistration::SIZE_MEDIUM,
                    LicenseRegistration::SIZE_LARGE,
                    LicenseRegistration::SIZE_ENTERPRISE,
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'organization_name.required' => 'Organization name is required.',
            'contact_name.required' => 'Contact person name is required.',
            'contact_email.required' => 'Contact email is required.',
            'contact_email.email' => 'Please provide a valid email address.',
            'contact_email.unique' => 'This email is already registered.',
            'vat_number.size' => 'VAT number must be exactly 15 digits.',
            'vat_number.regex' => 'Invalid Saudi VAT number format. Must start with 3 and be 15 digits.',
            'use_case_description.required' => 'Please describe your intended use case.',
            'use_case_description.min' => 'Use case description must be at least 20 characters.',
            'terms_accepted.required' => 'You must accept the Terms of Use.',
            'terms_accepted.accepted' => 'You must accept the Terms of Use to register.',
        ];
    }

    public function attributes(): array
    {
        return [
            'organization_name' => 'organization name',
            'contact_name' => 'contact person name',
            'contact_email' => 'contact email',
            'vat_number' => 'VAT registration number',
            'use_case_description' => 'use case description',
            'terms_accepted' => 'terms acceptance',
            'license_type' => 'license type',
            'country_code' => 'country',
            'company_size' => 'company size',
        ];
    }
}
