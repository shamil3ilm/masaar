<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Settings\FinanceSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankAccountRequest extends FormRequest
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
            'currency' => ['required', 'string', Rule::in(FinanceSettings::billable())],
            'bank_name' => ['required', 'string', 'max:120'],
            'account_name' => ['required', 'string', 'max:120'],
            'iban' => ['required', 'string', 'max:42'],
            'swift' => ['nullable', 'string', 'max:11'],
        ];
    }
}
