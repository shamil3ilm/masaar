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
     * Ask the provider what became of a document, for when a callback was missed.
     *
     * @throws NoAspConfiguredException
     * @throws AspUnavailableException
     */
    public function status(string $messageId): AspStatus;

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
