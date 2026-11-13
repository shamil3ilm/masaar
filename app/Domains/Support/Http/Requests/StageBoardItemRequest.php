<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Requests;

use App\Domains\Support\Enums\RequestStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StageBoardItemRequest extends FormRequest
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
            'stage' => ['required', Rule::enum(RequestStage::class)],
            'note' => ['nullable', 'string', 'max:5000'],
            'note_ar' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
