<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Licensing\Enums\LicenseTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveOrganizationRequest extends FormRequest
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
            'tier' => ['required', Rule::in(array_column(LicenseTier::cases(), 'value'))],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
