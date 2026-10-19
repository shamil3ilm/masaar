<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Contracts;

use App\Domains\Compliance\Networks\Peppol\Exceptions\AspRefusedException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspUnavailableException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\NoAspConfiguredException;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspStatus;
use App\Domains\Compliance\Networks\Peppol\Gateways\InboundMessage;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use Illuminate\Http\Request;

/**
 * The accredited service provider (ASP), as the platform needs it.
 *
 * The platform is Corner 1: it builds the document and hands it over. The
 * provider (Corner 2) carries it to the buyer's provider, reports it to the
 * authority, and calls back with what happened. No provider is chosen, so
 * nothing outside the gateways knows whose API this is; an accredited access
 * point of our own would be one more implementation.
 *
 * Bound in NetworkServiceProvider: HttpAspGateway when a base URL is set,
 * NullAspGateway when not. FakeAspGateway stands in for both in tests.
 */
interface AspGateway
{
    /**
     * Hand the document to the provider.
     *
     * @return string the provider's message id, which every later status refers to
     *
     * @throws NoAspConfiguredException no provider: the document waits for a manual upload
     * @throws AspUnavailableException no verdict (unreachable, throttled, 5xx): try again later
     * @throws AspRefusedException the provider refused the document itself
     */
    public function submit(PeppolDocument $document): string;

    /**
     * Ask the provider what became of a document, for when a callback was
     * missed: one status per track it has news on - delivery and the report
     * to the authority - since the provider confirms each on its own.
     *
     * @return list<AspStatus>
     *
     * @throws NoAspConfiguredException
     * @throws AspUnavailableException
     */
    public function status(string $messageId): array;

    /**
     * Ask the provider to report a document's tax data to the authority
     * again, after it relayed a failed report. The answer arrives as a
     * reporting status, by callback or lookup, like the first one.
     *
     * The taxpayer stays responsible for the report (the Electronic
     * Invoicing Guidelines v1.1, p11 note 2; p45: ensure the confirmation
     * for all tax data), so a failed one is not left to chance.
     *
     * @throws NoAspConfiguredException
     * @throws AspUnavailableException
     * @throws AspRefusedException the provider will not report it again
     */
    public function retryReport(string $messageId): void;

    /**
     * Whether a callback really comes from the provider, and recently.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * A verified status callback.
     */
    public function parseStatusWebhook(Request $request): AspStatus;

    /**
     * A verified callback delivering a document addressed to one of ours.
     */
    public function parseInboundWebhook(Request $request): InboundMessage;
}
