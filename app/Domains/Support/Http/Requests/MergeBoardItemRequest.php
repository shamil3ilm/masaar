<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MergeBoardItemRequest extends FormRequest
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
            'into_id' => ['required', 'uuid'],
        ];
    }
}
