<?php

declare(strict_types=1);

namespace App\Domains\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNameRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
