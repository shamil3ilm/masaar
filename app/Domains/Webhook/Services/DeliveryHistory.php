<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use Illuminate\Support\Collection;

/**
 * One endpoint's recent deliveries, as its owner reads them in the portal:
 * each event once, with every attempt at it - the queue retries an
 * unanswered delivery under the same delivery id (DeliverWebhook) - and what
 * was sent and answered with personal data taken out.
 *
 * Read only through the endpoint, which the caller has already found within
 * the organization: webhook_logs has no tenant of its own.
 */
class DeliveryHistory
{
    /** Attempts read for one page: enough for the last few dozen deliveries and their retries. */
    private const ATTEMPTS = 200;

    /**
     * Keys whose values carry no personal data: identifiers, states, codes,
     * amounts and times. Everything else in a payload is shown as redacted -
     * a buyer's name, an address, a participant identifier, an error message
     * that may quote any of them.
     */
    private const SAFE_KEYS = [
        'id', 'event', 'timestamp', 'data', 'e_invoice', 'message', 'webhook_id',
        'invoice_id', 'invoice_number', 'number', 'submission_id', 'org_id', 'status', 'state', 'previous_state',
        'type', 'invoice_type', 'document_type', 'submission_type', 'direction', 'reporting_status',
        'clearance_status', 'total', 'total_amount', 'currency', 'issue_date', 'cleared_at', 'next_retry_at',
        'icv', 'error_code', 'mls_code', 'retry_count', 'max_retries', 'will_retry', 'message_id',
        'certificate_id', 'expires_at', 'days_remaining', 'step',
    ];

    public const REDACTED = '[redacted]';

    /**
     * @return list<array{delivery_id: string, event: string, attempts: int, status: int, delivered: bool, last_attempt: mixed, duration_ms: int, payload: string, response: string}>
     */
    public function recent(Webhook $webhook, int $deliveries = 25): array
    {
        $attempts = WebhookLog::query()
            ->where('webhook_id', $webhook->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::ATTEMPTS)
            ->get();

        return $attempts
            ->groupBy(fn (WebhookLog $log): string => (string) ($log->payload['id'] ?? $log->id))
            ->take($deliveries)
            ->map(fn (Collection $tries, string $deliveryId): array => $this->delivery($deliveryId, $tries))
            ->values()
            ->all();
    }

    /**
     * A payload with every value that is not known to be free of personal
     * data replaced, keys kept so its shape still reads.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        $kept = [];

        foreach ($payload as $key => $value) {
            $safe = is_int($key) || in_array($key, self::SAFE_KEYS, true);

            $kept[$key] = match (true) {
                is_array($value) && $safe => $this->redact($value),
                is_array($value) => $value === [] ? [] : self::REDACTED,
                $value === null, is_bool($value) => $value,
                $safe => $value,
                default => self::REDACTED,
            };
        }

        return $kept;
    }

    /**
     * What a receiver answered, with addresses and long numbers - an email,
     * a phone, a tax or identity number: nine digits or more, spaced or not -
     * masked. The caller cuts it short.
     */
    public function mask(string $text): string
    {
        $text = (string) preg_replace('/[^\s@<>"\']+@[^\s@<>"\']+\.[A-Za-z]{2,}/', self::REDACTED, $text);

        return (string) preg_replace('/\+?\d(?:\s?\d){8,}/', self::REDACTED, $text);
    }

    /**
     * @param  Collection<int, WebhookLog>  $tries  newest first
     * @return array{delivery_id: string, event: string, attempts: int, status: int, delivered: bool, last_attempt: mixed, duration_ms: int, payload: string, response: string}
     */
    private function delivery(string $deliveryId, Collection $tries): array
    {
        /** @var WebhookLog $last */
        $last = $tries->first();

        return [
            'delivery_id' => $deliveryId,
            'event' => (string) $last->event,
            'attempts' => $tries->count(),
            // 0: no answer at all - refused before sending, or nothing came back.
            'status' => (int) $last->response_status,
            'delivered' => $tries->contains(fn (WebhookLog $try): bool => (bool) $try->success),
            'last_attempt' => $last->created_at,
            'duration_ms' => (int) $last->duration_ms,
            'payload' => (string) json_encode($this->redact((array) $last->payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'response' => mb_substr($this->mask((string) $last->response_body), 0, 500),
        ];
    }
}
