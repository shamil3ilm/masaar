<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organization = app(TenantResolver::class)->getOrganization();
        $branchId = (string) $this->route('branch');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            'street' => ['sometimes', 'string', 'max:255'],
            'building_number' => ['sometimes', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'additional_number' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'cr_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{1,50}$/'],
            'district' => ['sometimes', 'string', 'max:100'],
            'city' => ['sometimes', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'string', 'size:5', 'regex:/^\d{5}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'device_serial' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('branches', 'device_serial')
                    ->where('org_id', $organization->id)
                    ->ignore($branchId),
            ],
        ];
    }
}
