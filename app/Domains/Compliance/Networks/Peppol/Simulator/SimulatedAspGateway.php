<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Simulator;

use App\Domains\Compliance\Networks\Peppol\Console\PintValidator;
use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspRefusedException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspUnavailableException;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspStatus;
use App\Domains\Compliance\Networks\Peppol\Gateways\InboundMessage;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookPayload;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Receiving\InboundDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A service provider played inside this application, so the whole flow can
 * run with no provider contracted (PEPPOL_GATEWAY=simulator).
 *
 * It does what the platform relies on a provider for:
 *
 * - judges each document with the published rules (PintValidator) and
 *   refuses one that fails, each error with its rule ID, severity and
 *   location;
 * - takes a document once per idempotency key - the document id, as
 *   HttpAspGateway sends it - so a retry gets the first message id back and
 *   nothing is filed twice;
 * - calls back about it through the signed webhook routes, one step apart
 *   (PlaySimulatedMessage): its delivery and its report to the authority,
 *   each on its own, and answers status lookups from its own record;
 * - reports a document again when asked, after a failed report;
 * - delivers a document addressed to one of this platform's organizations
 *   to it, through the inbound route, as the buyer's provider would.
 *
 * What it plays for a document is chosen by the buyer's endpoint (Outcome).
 *
 * Never in production: NetworkServiceProvider refuses to boot, and to build
 * this, under APP_ENV=production. Nothing it does leaves this server.
 */
final class SimulatedAspGateway implements AspGateway
{
    /** As many errors as a provider returns at once. */
    private const MAX_ERRORS = 50;

    private readonly WebhookSignature $signature;

    public function __construct(
        private readonly SimulatorLedger $ledger,
        private readonly PintValidator $validator,
        private readonly InboundDocuments $inbound,
    ) {
        $this->signature = new WebhookSignature(SimulatorCallbacks::secret());
    }

    public function submit(PeppolDocument $document): string
    {
        $key = (string) $document->id;

        return $this->ledger->exclusively($key, function () use ($document, $key): string {
            // Seen before: the same answer, and nothing filed twice.
            $known = $this->ledger->messageIdFor($key);

            if ($known !== null) {
                return $known;
            }

            $outcome = Outcome::forReceiver($document->receiver_endpoint);
            $attempt = $this->ledger->attempt($key);
            $firstAttempt = $attempt === 1;

            if ($attempt <= $outcome->unansweredAttempts()) {
                throw new AspUnavailableException('The service provider gave no verdict (simulated HTTP 503).');
            }

            $this->judge((string) $document->xml);

            $message = new SimulatedMessage(
                messageId: 'sim-'.Str::uuid(),
                idempotencyKey: $key,
                outcome: $outcome->value,
                sender: (string) $document->sender_endpoint,
                receiver: (string) $document->receiver_endpoint,
                xml: (string) $document->xml,
                acceptedAt: now()->getTimestamp(),
                events: $outcome->events(
                    (string) $document->receiver_endpoint,
                    $this->inbound->receives((string) $document->receiver_endpoint),
                ),
            );

            $this->ledger->save($message);
            PlaySimulatedMessage::after($message->messageId, $this->step());

            if (! $outcome->answersFirstAttempt() && $firstAttempt) {
                throw new AspUnavailableException('The service provider took the document but its answer was lost (simulated timeout).');
            }

            return $message->messageId;
        });
    }

    public function status(string $messageId): array
    {
        return $this->known($messageId)->statusAt(now()->getTimestamp(), $this->step());
    }

    /**
     * The message taken under the idempotency key, if any - as the provider
     * keeps it whatever became of the answer to the submission.
     */
    public function findByReference(string $reference): ?AspStatus
    {
        $messageId = $this->ledger->messageIdFor($reference);

        return $messageId === null ? null : ($this->status($messageId)[0] ?? new AspStatus($messageId, AspStatus::SENT));
    }

    /**
     * Report it again: the authority's confirmation follows, a step later,
     * through the same callback.
     */
    public function retryReport(string $messageId): void
    {
        $this->ledger->exclusively($messageId, function () use ($messageId): void {
            $message = $this->known($messageId);
            $more = $message->withMoreEvents([Outcome::reported()]);

            $this->ledger->save($more);

            // Its last callback was played and nothing is queued for it.
            if ($message->nextEvent() === null) {
                PlaySimulatedMessage::after($messageId, $this->step());
            }
        });
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

    /**
     * @throws AspUnavailableException a message the provider does not have, as a 404
     */
    private function known(string $messageId): SimulatedMessage
    {
        return $this->ledger->find($messageId)
            ?? throw new AspUnavailableException('The service provider does not know the message (simulated HTTP 404).');
    }

    /**
     * @throws AspRefusedException the document breaks the published rules
     * @throws AspUnavailableException the rules cannot be run here
     */
    private function judge(string $xml): void
    {
        if (! $this->validator->available()) {
            throw new AspUnavailableException('The simulator cannot judge documents here: set PEPPOL_ARTEFACTS_PATH and PEPPOL_XSLT_JAR.');
        }

        try {
            $failures = $this->validator->failures($xml);
        } catch (RuntimeException $e) {
            throw new AspUnavailableException('The simulator could not run the rules: '.$e->getMessage());
        }

        if ($failures !== []) {
            throw new AspRefusedException(
                'The service provider refused the document: it breaks '.count($failures).' of the published rules (simulated HTTP 422).',
                array_slice($failures, 0, self::MAX_ERRORS),
            );
        }
    }

    private function step(): int
    {
        return max(0, (int) config('peppol.simulator.step_seconds', 0));
    }
}
