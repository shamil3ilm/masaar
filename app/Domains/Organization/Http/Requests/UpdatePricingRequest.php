<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePricingRequest extends FormRequest
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
            'prices_include_vat' => ['required', 'boolean'],
        ];
    }
}
