<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RotateWebhookSecretRequest extends FormRequest
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
            'revoke_previous' => ['sometimes', 'boolean'],
        ];
    }
}
