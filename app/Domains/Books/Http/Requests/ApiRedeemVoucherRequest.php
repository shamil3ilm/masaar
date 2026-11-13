<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApiRedeemVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_id' => ['nullable', 'uuid', 'required_without:invoice_number'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'redeemed_on' => ['nullable', 'date'],
        ];
    }
}
