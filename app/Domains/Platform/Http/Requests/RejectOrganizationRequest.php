<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectOrganizationRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
