<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Requests;

use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Platform\Access\Feature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgreeCustomizationRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:5000'],
            'billable' => ['required', 'boolean'],
            'price' => ['nullable', 'required_if:billable,1', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'currency' => ['nullable', 'required_with:price', Rule::in(FinanceSettings::billable())],
            'features' => ['array'],
            'features.*' => [Rule::enum(Feature::class)],
        ];
    }
}
