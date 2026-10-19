<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Simulator;

use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The simulated provider calling this platform back, over HTTP, signed, at
 * the same two routes a real provider calls - so the signature check, the
 * body validation and the controllers are the ones in production.
 *
 * Bodies are WebhookPayload's generic shape and the signature
 * WebhookSignature's, the shapes HttpAspGateway expects until a provider's
 * own are known.
 */
final class SimulatorCallbacks
{
    public const STATUS_PATH = '/api/peppol/webhooks/status';

    public const INBOUND_PATH = '/api/peppol/webhooks/inbound';

    private const TIMEOUT = 15;

    /**
     * The secret callbacks are signed with: the provider's, when one is
     * configured, else one derived from the application key - so a staging
     * server reachable from outside still refuses a callback nobody here
     * signed, and nothing needs configuring for a demo.
     */
    public static function secret(): string
    {
        $configured = config('peppol.asp.webhook_secret');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return hash_hmac('sha256', 'peppol-simulator-callbacks', (string) config('app.key'));
    }

    /**
     * Deliver one callback.
     *
     * @param  array{type: string, status?: string, mls_code?: string|null, errors?: list<string>, authority_reference?: string|null}  $event
     * @return int the HTTP status this platform answered, 0 when it could not be reached
     */
    public function deliver(SimulatedMessage $message, array $event): int
    {
        if ($event['type'] === 'inbound') {
            return $this->post(self::INBOUND_PATH, [
                'message_id' => $message->messageId,
                'document' => base64_encode($message->xml),
                'sender' => $message->sender,
                'receiver' => $message->receiver,
            ]);
        }

        return $this->post(self::STATUS_PATH, [
            'message_id' => $message->messageId,
            'status' => $event['status'] ?? null,
            'mls_code' => $event['mls_code'] ?? null,
            'errors' => $event['errors'] ?? [],
            'authority_reference' => $event['authority_reference'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function post(string $path, array $body): int
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        try {
            return Http::timeout(self::TIMEOUT)
                ->acceptJson()
                ->withHeaders([
                    WebhookSignature::TIMESTAMP_HEADER => $timestamp,
                    WebhookSignature::SIGNATURE_HEADER => 'sha256='.(new WebhookSignature(self::secret()))->sign($timestamp, $json),
                ])
                ->withBody($json, 'application/json')
                ->post($this->url($path))
                ->status();
        } catch (ConnectionException) {
            return 0;
        }
    }

    private function url(string $path): string
    {
        $base = config('peppol.simulator.callback_url') ?: config('app.url');

        return rtrim((string) $base, '/').$path;
    }
}
