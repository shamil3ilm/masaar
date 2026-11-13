<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReopenVatPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
