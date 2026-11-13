<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http\Requests;

use App\Domains\Webhook\Rules\PublicEndpoint;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Foundation\Http\FormRequest;

class CreateWebhookRequest extends FormRequest
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
            'url' => ['required', 'url', 'max:500', new PublicEndpoint],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', WebhookService::EVENTS).',*'],
        ];
    }
}
