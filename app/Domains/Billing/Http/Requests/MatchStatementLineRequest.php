<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MatchStatementLineRequest extends FormRequest
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
            'organization' => ['required', 'uuid'],
            'amounts' => ['array'],
            'amounts.*' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'payments' => ['array'],
            'payments.*' => ['uuid'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
