<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\DTOs;

/**
 * The provider's answer to a submission: the message id every later status
 * refers to, and the provider's own id for the document where it keeps one
 * apart - kept beside the document's UUID, which is the platform's
 * (PeppolDocument::document_uuid), as a reference.
 */
final readonly class SubmitReceipt
{
    public function __construct(
        public string $messageId,
        public ?string $providerReference = null,
    ) {}
}
