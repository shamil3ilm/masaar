<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Gateways;

/**
 * A document the provider received on the network for one of our
 * participants, as it travelled.
 */
final readonly class InboundMessage
{
    public function __construct(
        public string $messageId,
        public string $xml,
        public string $senderEndpoint,
        public string $receiverEndpoint,
    ) {}
}
