<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FileVatPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filed_on' => ['required', 'date'],
        ];
    }
}
