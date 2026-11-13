<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Licensing\Enums\LicenseTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordBillingPaymentRequest extends FormRequest
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
            'tier' => ['required', Rule::enum(LicenseTier::class)],
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
