<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Simulator;

use App\Domains\Compliance\Networks\Peppol\Gateways\AspStatus;

/**
 * What the simulator plays for one document, chosen by its buyer's endpoint
 * (peppol.simulator.outcomes); anything not listed is delivered.
 *
 * Each outcome is the provider's callbacks, in the order a provider sends
 * them. The first answer to the submission is the gateway's to give
 * (SimulatedAspGateway::submit); these are what comes after.
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

    public static function forReceiver(?string $endpoint): self
    {
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
     * @return list<array{type: string, status?: string, mls_code?: string|null, errors?: list<string>}>
     */
    public function events(string $receiver, bool $receiverIsHere): array
    {
        $sent = self::status(AspStatus::SENT);
        $reported = self::status(AspStatus::REPORTED);

        return match ($this) {
            self::Delivered, self::Unavailable, self::AnswerLost => [
                $sent,
                self::status(AspStatus::DELIVERED, 'AP'),
                $reported,
                ...($receiverIsHere ? [['type' => 'inbound']] : []),
            ],
            self::Rejected => [
                $sent,
                self::status(AspStatus::REJECTED, 'RE', ['BV: The buyer\'s access point refused the document (simulated rejection).']),
            ],
            self::UnknownReceiver => [
                self::status(AspStatus::REJECTED, null, ["The receiver {$receiver} is not a participant on the network: no capability lookup entry (simulated)."]),
            ],
            self::ReportOnly => [$sent, $reported],
        };
    }

    /**
     * @param  list<string>  $errors
     * @return array{type: string, status: string, mls_code: string|null, errors: list<string>}
     */
    private static function status(string $status, ?string $mls = null, array $errors = []): array
    {
        return ['type' => 'status', 'status' => $status, 'mls_code' => $mls, 'errors' => $errors];
    }
}
