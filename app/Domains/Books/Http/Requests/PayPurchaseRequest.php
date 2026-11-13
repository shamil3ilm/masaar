<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PayPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
