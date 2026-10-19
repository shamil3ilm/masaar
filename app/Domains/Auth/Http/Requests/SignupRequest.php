<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Requests;

use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Services\BusinessTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * A business's sign-up: the person, and the company ZATCA will know.
 *
 * The company's details are the ones onboarding requires, in ZATCA's shapes,
 * so an approved sign-up can go straight on to its OTP. The company's name
 * fields are prefixed, since the person has a name too.
 *
 * The company is checked in its own country's shapes, and only the fields
 * that country's sign-up section sends (its module's SellerRules). Where the
 * deployment registers more than one country the form asks which; otherwise
 * nothing is sent and it is the default country.
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
    public function rules(BusinessTypes $businessTypes): array
    {
        $country = $this->companyCountry();
        $company = CompanyProfileRules::rules($country, complete: true);
        $fields = CompanyProfileRules::jurisdiction($country)->sellerRules()->signupFields();

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
            ...Arr::only($company, $fields),

            // What the business mostly is, from the types its country
            // serves: defaults only, so it may be left out.
            'business_type' => ['nullable', 'string', Rule::in($businessTypes->offeredKeys(CompanyProfileRules::jurisdiction($country)))],

            'terms' => ['accepted'],
        ];
    }

    /**
     * The country the company is registered in: the one chosen, if this
     * deployment registers it. One it does not is refused by the country
     * rule, and the rest is checked as the default country's meanwhile.
     */
    private function companyCountry(): ?string
    {
        $chosen = $this->input('country');

        return in_array($chosen, CompanyProfileRules::countries(), true) ? $chosen : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...CompanyProfileRules::messages($this->companyCountry()),
            'password.uncompromised' => __('This password has appeared in a data breach. Please choose a different password.'),
            'terms.accepted' => __('Accept the terms to create an account.'),
        ];
    }
}
