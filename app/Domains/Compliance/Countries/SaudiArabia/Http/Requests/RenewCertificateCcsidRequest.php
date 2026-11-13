<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenewCertificateCcsidRequest extends FormRequest
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
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
            'common_name' => ['required', 'string', 'max:64'],
            'organization_unit' => ['nullable', 'string', 'max:64'],
            'industry' => ['nullable', 'string', 'max:64'],
            'renewal_override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
