<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SplitStatementMatchRequest extends FormRequest
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
            'amounts' => ['required', 'array'],
            'amounts.*' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
