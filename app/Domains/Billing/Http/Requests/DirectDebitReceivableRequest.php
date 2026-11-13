<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DirectDebitReceivableRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['bank_transfer', 'card'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
