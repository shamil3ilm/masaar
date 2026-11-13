<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organization = app(TenantResolver::class)->getOrganization();

        return [
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            'street' => ['required', 'string', 'max:255'],
            'building_number' => ['required', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'additional_number' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'cr_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{1,50}$/'],
            'district' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'size:5', 'regex:/^\d{5}$/'],
            'device_serial' => [
                'nullable', 'string', 'max:255',
                Rule::unique('branches', 'device_serial')->where('org_id', $organization->id),
            ],
        ];
    }
}
