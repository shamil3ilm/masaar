<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http\Requests;

use App\Domains\Webhook\Rules\PublicEndpoint;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWebhookRequest extends FormRequest
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
            'url' => ['sometimes', 'url', 'max:500', new PublicEndpoint],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', WebhookService::EVENTS).',*'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
