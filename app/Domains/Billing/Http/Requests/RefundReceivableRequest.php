<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Services\Refunds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundReceivableRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:240'],
            'reason_ar' => ['required', 'string', 'max:240'],
            'payout' => ['required', Rule::in(Refunds::PAYOUTS)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
