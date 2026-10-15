<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Organization\Rules\CompanyProfileRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * A business's sign-up: the person, and the company ZATCA will know.
 *
 * The company's details are the ones onboarding requires, in ZATCA's shapes,
 * so an approved sign-up can go straight on to its OTP. The company's name
 * fields are prefixed, since the person has a name too.
 */
class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = CompanyProfileRules::rules('SA', complete: true);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised(),
            ],

            'company_name' => $company['name'],
            'company_name_ar' => $company['name_ar'],
            'vat_number' => $company['vat_number'],
            'cr_number' => $company['cr_number'],
            'street' => $company['street'],
            'building_number' => $company['building_number'],
            'additional_street' => $company['additional_street'],
            'district' => $company['district'],
            'city' => $company['city'],
            'postal_code' => $company['postal_code'],

            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...CompanyProfileRules::messages(),
            'password.uncompromised' => 'This password has appeared in a data breach. Please choose a different password.',
            'terms.accepted' => 'Accept the terms to create an account.',
        ];
    }
}
