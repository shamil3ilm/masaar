<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Rules\VatNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateComplianceProfileRequest extends FormRequest
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
        return [
            'jurisdiction' => ['required', 'string', 'size:2'],
            'engine' => ['required', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in([
                ComplianceProfile::STATUS_PENDING,
                ComplianceProfile::STATUS_ACTIVE,
                ComplianceProfile::STATUS_SUSPENDED,
                ComplianceProfile::STATUS_REVOKED,
            ])],
            'settings' => ['sometimes', 'array:vat_number'],
            'settings.vat_number' => ['nullable', 'string', new VatNumber],
        ];
    }
}
