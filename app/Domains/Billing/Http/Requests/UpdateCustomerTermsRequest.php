<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Settings\FinanceSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerTermsRequest extends FormRequest
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
            'currency' => ['nullable', 'string', Rule::in(FinanceSettings::billable())],
            'free_months' => ['nullable', 'integer'],
            'payment_terms_days' => ['nullable', 'integer'],
            'reminder_days_before' => ['nullable', 'string', 'max:100'],
            'reminder_days_after' => ['nullable', 'string', 'max:100'],
        ];
    }
}
