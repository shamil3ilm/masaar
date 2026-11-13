<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVatSettingsRequest extends FormRequest
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
            'input_vat_recovery_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
