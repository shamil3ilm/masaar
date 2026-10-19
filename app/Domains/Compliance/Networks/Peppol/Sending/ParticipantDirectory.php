<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Sending;

use App\Domains\Compliance\Networks\Peppol\Exceptions\AspUnavailableException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\NoAspConfiguredException;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspGatewayResolver;
use App\Domains\Compliance\Networks\Peppol\Receiving\InboundDocuments;
use Illuminate\Support\Facades\Cache;

/**
 * Whether a buyer can be reached on the network, asked before a document is
 * issued to it.
 *
 * The supplier gathers the buyer's participant identifier and its provider
 * looks it up (Electronic Invoicing Guidelines v1.1, p11 rows 5-6; programme
 * presentation p7: through the directory before transmitting). Asked here
 * first, a buyer that is not on the network stops the invoice with a reason
 * the seller can act on, instead of a rejection after it is issued.
 *
 * Three answers: registered, not registered, or unknown - no provider, or
 * none answering - which stops nothing. A report-only endpoint is never
 * delivered to and is not asked about. Answers are kept for a while, a
 * refusal more briefly, so a buyer who has just registered is found again.
 */
final class ParticipantDirectory
{
    private const REGISTERED_SECONDS = 24 * 3600;

    private const UNREGISTERED_SECONDS = 600;

    public function __construct(
        private readonly AspGatewayResolver $gateways,
        private readonly ReportOnlyEndpoints $reportOnly,
    ) {}

    /**
     * @param  string|null  $organizationId  whose provider asks: each looks up in its own way
     * @return bool|null true registered, false not, null unknown
     */
    public function reachable(?string $endpoint, ?string $organizationId = null): ?bool
    {
        $endpoint = InboundDocuments::endpoint((string) $endpoint);

        if ($endpoint === '' || $this->reportOnly->contains($endpoint)) {
            return null;
        }

        $provider = $this->gateways->providerFor($organizationId);
        $key = 'peppol:participant:'.hash('sha256', $provider.'|'.$endpoint);
        $known = Cache::get($key);

        if (is_bool($known)) {
            return $known;
        }

        try {
            $registered = $this->gateways->forOrganization($organizationId)->participantExists($endpoint);
        } catch (NoAspConfiguredException|AspUnavailableException) {
            return null;
        }

        Cache::put($key, $registered, $registered ? self::REGISTERED_SECONDS : self::UNREGISTERED_SECONDS);

        return $registered;
    }
}
