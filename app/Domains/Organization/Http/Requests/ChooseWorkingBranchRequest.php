<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChooseWorkingBranchRequest extends FormRequest
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
            'branch' => ['nullable', 'string', 'uuid'],
        ];
    }
}
