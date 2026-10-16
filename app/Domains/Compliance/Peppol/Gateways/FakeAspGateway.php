<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Gateways;

use App\Domains\Compliance\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use Illuminate\Http\Request;
use Throwable;

/**
 * A provider that answers from a script, for tests.
 *
 * Deterministic: the message id for a document is "fake-{document id}", and
 * every call is recorded so a test can assert what was - and was not - sent.
 * Callbacks are verified and parsed exactly as the HTTP gateway does, so the
 * webhook tests exercise the real signature check.
 */
final class FakeAspGateway implements AspGateway
{
    /** @var list<string> ids of the documents submitted, in order */
    public array $submitted = [];

    /** @var list<string> message ids asked about, in order */
    public array $queried = [];

    /** @var list<Throwable> thrown by the next submits, one each, before any succeeds */
    private array $failures = [];

    /** @var array<string, AspStatus> */
    private array $statuses = [];

    private readonly WebhookSignature $signature;

    public function __construct(?string $webhookSecret = 'fake-webhook-secret')
    {
        $this->signature = new WebhookSignature($webhookSecret);
    }

    /**
     * The next submit throws this instead of answering.
     */
    public function failNextWith(Throwable $failure): self
    {
        $this->failures[] = $failure;

        return $this;
    }

    /**
     * What status() answers for a message.
     */
    public function answer(AspStatus $status): self
    {
        $this->statuses[$status->messageId] = $status;

        return $this;
    }

    public function submit(PeppolDocument $document): string
    {
        $this->submitted[] = (string) $document->id;

        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        return 'fake-'.$document->id;
    }

    public function status(string $messageId): AspStatus
    {
        $this->queried[] = $messageId;

        return $this->statuses[$messageId] ?? new AspStatus($messageId, null);
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->signature->verify($request);
    }

    public function parseStatusWebhook(Request $request): AspStatus
    {
        return WebhookPayload::status($request);
    }

    public function parseInboundWebhook(Request $request): InboundMessage
    {
        return WebhookPayload::inbound($request);
    }
}
