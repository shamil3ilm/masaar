<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Enums\OutgoingChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPayablePaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'channel' => ['required', Rule::enum(OutgoingChannel::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_from' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
