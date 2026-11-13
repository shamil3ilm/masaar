<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClaimBadDebtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'written_off_on' => ['required', 'date'],
            'notified_on' => ['nullable', 'date'],
            'certificate' => ['nullable', 'string', 'max:200'],
            'evidence' => ['nullable', 'string', 'max:200'],
            'unrelated' => ['nullable', 'boolean'],
        ];
    }
}
