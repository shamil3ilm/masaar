<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkBoardItemRequest extends FormRequest
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
            'item_id' => ['required', 'uuid'],
        ];
    }
}
