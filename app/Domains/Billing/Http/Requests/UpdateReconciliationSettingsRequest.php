<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReconciliationSettingsRequest extends FormRequest
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
            'tolerance' => ['required', 'numeric'],
            'window_days' => ['required', 'integer'],
            'fee_write_off' => ['required', 'numeric'],
        ];
    }
}
