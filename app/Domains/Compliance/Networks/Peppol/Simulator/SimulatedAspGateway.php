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
 *   refuses one that fails, with the rule IDs as the errors;
 * - takes a document once per idempotency key - the document id, as
 *   HttpAspGateway sends it - so a retry gets the first message id back and
 *   nothing is filed twice;
 * - calls back about it through the signed webhook routes, one step apart
 *   (PlaySimulatedMessage), and answers status lookups from its own record;
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
            $firstAttempt = $this->ledger->attempt($key) === 1;

            if ($outcome === Outcome::Unavailable && $firstAttempt) {
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

    public function status(string $messageId): AspStatus
    {
        $message = $this->ledger->find($messageId);

        if ($message === null) {
            throw new AspUnavailableException('The service provider did not answer the status lookup (simulated HTTP 404).');
        }

        return $message->statusAt(now()->getTimestamp(), $this->step());
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
