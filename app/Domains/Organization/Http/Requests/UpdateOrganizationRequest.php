<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\CompanyProfileRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
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
        $organization = $this->route('organization');
        $org = $organization instanceof Organization ? $organization : $this->user()?->organizations()->find($organization);
        $country = $org?->country ?? 'SA';

        return [
            ...CompanyProfileRules::rules($country, complete: false, existing: $org),
            'prices_include_vat' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $organization = $this->route('organization');
        $country = $organization instanceof Organization ? $organization->country : ($this->user()?->organizations()->find($organization)?->country ?? 'SA');

        return CompanyProfileRules::messages($country);
    }
}
