<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierCreditRequest extends FormRequest
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
            'net' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'credit_date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_credit_number' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
