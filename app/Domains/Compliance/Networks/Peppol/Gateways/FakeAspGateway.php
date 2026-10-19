<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Gateways;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\DTOs\SubmitReceipt;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
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

    /** @var list<string> message ids whose report was asked for again, in order */
    public array $reportRetried = [];

    /** @var list<Throwable> thrown by the next submits, one each, before any succeeds */
    private array $failures = [];

    private ?Throwable $reportRetryFailure = null;

    /** @var list<string> references looked up, in order */
    public array $lookedUp = [];

    /** @var list<string> participant endpoints looked up, in order */
    public array $participantsLookedUp = [];

    /** @var list<string> */
    private array $unregistered = [];

    /** @var array<string, AspStatus> */
    private array $held = [];

    /** @var array<string, list<AspStatus>> */
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
     * What status() answers for a message: one status per track.
     */
    public function answer(AspStatus ...$statuses): self
    {
        foreach ($statuses as $status) {
            $this->statuses[$status->messageId][] = $status;
        }

        return $this;
    }

    public function submit(PeppolDocument $document): SubmitReceipt
    {
        $this->submitted[] = (string) $document->id;

        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        return new SubmitReceipt('fake-'.$document->id);
    }

    public function status(string $messageId): array
    {
        $this->queried[] = $messageId;

        return $this->statuses[$messageId] ?? [];
    }

    /**
     * What findByReference() answers: the provider holds this message under
     * the reference.
     */
    public function holds(string $reference, string $messageId, ?string $status = AspStatus::SENT): self
    {
        $this->held[$reference] = new AspStatus($messageId, $status);

        return $this;
    }

    /**
     * participantExists() answers false for this endpoint; every other is registered.
     */
    public function unregistered(string $endpoint): self
    {
        $this->unregistered[] = $endpoint;

        return $this;
    }

    public function participantExists(string $endpoint): bool
    {
        $this->participantsLookedUp[] = $endpoint;

        return ! in_array($endpoint, $this->unregistered, true);
    }

    public function findByReference(string $reference): ?AspStatus
    {
        $this->lookedUp[] = $reference;

        return $this->held[$reference] ?? null;
    }

    public function retryReport(string $messageId): void
    {
        $this->reportRetried[] = $messageId;

        if ($this->reportRetryFailure !== null) {
            throw $this->reportRetryFailure;
        }
    }

    /**
     * Every retryReport() throws this.
     */
    public function failReportRetriesWith(Throwable $failure): self
    {
        $this->reportRetryFailure = $failure;

        return $this;
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
