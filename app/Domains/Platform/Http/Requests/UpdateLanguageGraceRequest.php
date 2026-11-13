<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLanguageGraceRequest extends FormRequest
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
            'until' => ['nullable', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
