<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveBookSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'name_ar' => ['nullable', 'string', 'max:200'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:100'],
            'subdivision' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
