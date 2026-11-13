<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use App\Domains\Organization\Rules\CompanyProfileRules;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrganizationRequest extends FormRequest
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
        $country = $this->input('country') ?: null;
        $jurisdiction = CompanyProfileRules::jurisdiction(is_string($country) ? $country : null);

        return [
            ...CompanyProfileRules::rules($jurisdiction->code(), complete: false),
            ...CompanyProfileRules::country(),
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $country = $this->input('country') ?: null;
        $jurisdiction = CompanyProfileRules::jurisdiction(is_string($country) ? $country : null);

        return CompanyProfileRules::messages($jurisdiction->code());
    }
}
