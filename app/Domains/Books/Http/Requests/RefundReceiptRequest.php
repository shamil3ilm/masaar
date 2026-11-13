<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use App\Domains\Books\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundReceiptRequest extends FormRequest
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
            'method' => ['required', Rule::enum(PaymentMethod::class)->except([PaymentMethod::Voucher])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
