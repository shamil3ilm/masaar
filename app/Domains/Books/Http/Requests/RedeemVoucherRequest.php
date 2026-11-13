<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'redeemed_on' => ['required', 'date'],
        ];
    }
}
