<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessOfflineQueueRequest extends FormRequest
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
            'limit' => ['nullable', 'integer', 'min:1'],
            'org_id' => ['nullable', 'uuid'],
        ];
    }
}
