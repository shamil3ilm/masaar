<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomChargeRequest extends FormRequest
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
            'description' => ['required', 'string', 'max:300'],
            'description_ar' => ['required', 'string', 'max:300'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'period_start' => ['nullable', 'date', 'required_with:period_end'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
