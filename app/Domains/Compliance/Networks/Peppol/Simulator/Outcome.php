<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Simulator;

use App\Domains\Compliance\Networks\Peppol\Gateways\AspError;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspStatus;
use App\Domains\Compliance\Networks\Peppol\Sending\ReportOnlyEndpoints;
use Illuminate\Support\Str;

/**
 * What the simulator plays for one document, chosen by its buyer's endpoint
 * (peppol.simulator.outcomes, and every report-only endpoint); anything not
 * listed is delivered.
 *
 * Each outcome is the provider's callbacks, in the order a provider sends
 * them. The first answer to the submission is the gateway's to give
 * (SimulatedAspGateway::submit); these are what comes after. Delivery and
 * the report to the authority are confirmed separately, as a provider
 * confirms them (Electronic Invoicing Guidelines v1.1, p10-11).
 */
enum Outcome: string
{
    /** Accepted, delivered to the buyer's access point, reported to the authority. */
    case Delivered = 'delivered';

    /** The buyer is not a participant on the network: nobody to deliver to. */
    case UnknownReceiver = 'unknown_receiver';

    /** No answer to the first attempt; the retry is taken and delivered. */
    case Unavailable = 'unavailable';

    /** The first attempt is taken but its answer lost; the retry gets the same message. */
    case AnswerLost = 'answer_lost';

    /** The buyer's access point refuses it: message level response RE. */
    case Rejected = 'rejected';

    /** Reported to the authority and never exchanged: a reserved endpoint. */
    case ReportOnly = 'report_only';

    /** Delivered, but the report to the authority fails until it is asked again. */
    case ReportFailed = 'report_failed';

    /** The authority's confirmation arrives before the buyer's provider's. */
    case ReportedFirst = 'reported_first';

    public static function forReceiver(?string $endpoint): self
    {
        if ((new ReportOnlyEndpoints)->contains($endpoint)) {
            return self::ReportOnly;
        }

        $outcomes = (array) config('peppol.simulator.outcomes', []);

        return self::tryFrom((string) ($outcomes[(string) $endpoint] ?? '')) ?? self::Delivered;
    }

    /** Whether the first attempt at submitting goes unanswered. */
    public function answersFirstAttempt(): bool
    {
        return ! in_array($this, [self::Unavailable, self::AnswerLost], true);
    }

    /**
     * The callbacks, in order.
     *
     * @param  bool  $receiverIsHere  the buyer is one of this platform's
     *                                organizations, so a delivery also arrives
     *                                as its inbound document
     * @return list<array{type: string, status?: string, mls_code?: string|null, errors?: list<string>, authority_reference?: string|null}>
     */
    public function events(string $receiver, bool $receiverIsHere): array
    {
        $sent = self::status(AspStatus::SENT);
        $delivered = self::status(AspStatus::DELIVERED, 'AP');
        $inbound = $receiverIsHere ? [['type' => 'inbound']] : [];

        return match ($this) {
            self::Delivered, self::Unavailable, self::AnswerLost => [$sent, $delivered, self::reported(), ...$inbound],
            self::ReportedFirst => [$sent, self::reported(), $delivered, ...$inbound],
            self::ReportFailed => [
                $sent,
                $delivered,
                self::status(AspStatus::REPORT_FAILED, null, [new AspError('The authority did not acknowledge the tax data (simulated reporting failure).', 'REPORT-NOT-ACKNOWLEDGED', AspError::ERROR)]),
                ...$inbound,
            ],
            self::Rejected => [
                $sent,
                self::status(AspStatus::REJECTED, 'RE', [new AspError('The buyer\'s access point refused the document (simulated rejection).', 'BV', AspError::FATAL)]),
                self::reported(),
            ],
            self::UnknownReceiver => [
                self::status(AspStatus::REJECTED, null, [new AspError("The receiver {$receiver} is not a participant on the network: no capability lookup entry (simulated).", 'PARTICIPANT-NOT-FOUND', AspError::FATAL, 'cac:AccountingCustomerParty/cac:Party/cbc:EndpointID')]),
            ],
            self::ReportOnly => [$sent, self::reported()],
        };
    }

    /**
     * The authority's confirmation, with the reference the provider relays.
     *
     * @return array{type: string, status: string, mls_code: string|null, errors: list<string>, authority_reference: string}
     */
    public static function reported(): array
    {
        return [...self::status(AspStatus::REPORTED), 'authority_reference' => 'SIM-'.strtoupper(Str::random(12))];
    }

    /**
     * @param  list<AspError>  $errors
     * @return array{type: string, status: string, mls_code: string|null, errors: list<array<string, string|null>>}
     */
    private static function status(string $status, ?string $mls = null, array $errors = []): array
    {
        return ['type' => 'status', 'status' => $status, 'mls_code' => $mls, 'errors' => AspError::toArrays($errors)];
    }
}
