<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Organization\Rules\CompanyProfileRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Password;

/**
 * A business's sign-up: the person, and the company ZATCA will know.
 *
 * The company's details are the ones onboarding requires, in ZATCA's shapes,
 * so an approved sign-up can go straight on to its OTP. The company's name
 * fields are prefixed, since the person has a name too.
 *
 * The company is checked in its own country's shapes. Where the deployment
 * registers more than one country the form asks which; otherwise nothing is
 * sent and it is Saudi Arabia. A UAE company gives everything its documents
 * need except the Peppol endpoint, which its service provider issues after
 * sign-up and the company page takes; issuing still checks for it.
 */
class SignupRequest extends FormRequest
{
    /** A Saudi company's details beyond its name, as ZATCA onboarding needs them. */
    private const SAUDI_FIELDS = [
        'vat_number', 'cr_number', 'street', 'building_number', 'additional_street', 'district', 'city', 'postal_code',
    ];

    /** A UAE company's details beyond its name. Not the Peppol endpoint, nor the optional payment account. */
    private const UAE_FIELDS = [
        'trn', 'tin', 'street', 'building_number', 'additional_street', 'district', 'city', 'postal_code', 'emirate',
        'legal_registration_type', 'legal_registration_id', 'legal_registration_authority',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $country = $this->companyCountry();
        $company = CompanyProfileRules::rules($country, complete: true);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised(),
            ],

            ...CompanyProfileRules::country(),
            'company_name' => $company['name'],
            'company_name_ar' => $company['name_ar'],
            ...Arr::only($company, $country === 'AE' ? self::UAE_FIELDS : self::SAUDI_FIELDS),

            'terms' => ['accepted'],
        ];
    }

    /**
     * The country the company is registered in: the one chosen, if this
     * deployment registers it. One it does not is refused by the country
     * rule, and the rest is checked as Saudi meanwhile.
     */
    private function companyCountry(): string
    {
        $chosen = $this->input('country');

        return in_array($chosen, CompanyProfileRules::countries(), true) ? $chosen : 'SA';
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...CompanyProfileRules::messages(),
            'password.uncompromised' => __('This password has appeared in a data breach. Please choose a different password.'),
            'terms.accepted' => __('Accept the terms to create an account.'),
        ];
    }
}
