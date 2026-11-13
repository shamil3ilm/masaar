<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveSupplierRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:200'],
            'country' => ['required', 'string', 'size:2'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'currency' => ['required', 'string', 'size:3'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'iban' => ['nullable', 'string', 'max:42'],
            'swift' => ['nullable', 'string', 'max:11'],
            'category' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
