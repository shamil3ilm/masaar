<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OfferOwnershipRequest extends FormRequest
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
            'user_id' => ['required', 'uuid'],
            'demote_to' => ['required', 'string', 'max:20'],
        ];
    }
}
