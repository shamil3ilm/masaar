<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Gateways;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Exceptions\NoAspConfiguredException;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use Illuminate\Http\Request;

/**
 * No provider connected (PEPPOL_ASP_BASE_URL empty).
 *
 * Documents are still built and kept, and stay ready for a person to
 * download and upload through the provider's portal. Nothing is sent, and no
 * callback is believed: with no provider there is nobody to sign one.
 */
final class NullAspGateway implements AspGateway
{
    public function submit(PeppolDocument $document): string
    {
        throw NoAspConfiguredException::make();
    }

    public function status(string $messageId): AspStatus
    {
        throw NoAspConfiguredException::make();
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    public function parseStatusWebhook(Request $request): AspStatus
    {
        throw NoAspConfiguredException::make();
    }

    public function parseInboundWebhook(Request $request): InboundMessage
    {
        throw NoAspConfiguredException::make();
    }
}
