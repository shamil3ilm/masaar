<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\DTOs;

/**
 * What a received document says about itself, read from its XML.
 *
 * Every field is plain text taken from the document as the supplier wrote
 * it, blank where the document does not say: it is shown to a person,
 * escaped, and never trusted for anything else.
 */
final readonly class ReceivedSummary
{
    public function __construct(
        public string $kind = '',
        public string $number = '',
        public string $issueDate = '',
        public string $dueDate = '',
        public string $seller = '',
        public string $sellerTaxId = '',
        public string $currency = '',
        public string $total = '',
        public string $tax = '',
    ) {}

    public function isCreditNote(): bool
    {
        return $this->kind === 'CreditNote';
    }
}
