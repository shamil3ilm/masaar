<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Settings\FinanceSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMissingBillAlertsRequest extends FormRequest
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
            'missing_bill_days' => ['required', 'integer'],
            'large_payment_threshold' => ['required', 'numeric'],
            'fee_source' => ['nullable', 'in:'.FinanceSettings::FEES_FROM_PAYMENTS.','.FinanceSettings::FEES_FROM_EXPENSES],
            'fee_categories' => ['nullable', 'array'],
            'fee_categories.*' => ['string', Rule::in(array_keys(app(FinanceSettings::class)->expenseCategories()))],
        ];
    }
}
