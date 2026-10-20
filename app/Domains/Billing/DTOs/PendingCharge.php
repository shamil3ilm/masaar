<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

/**
 * Something an organization owes beyond its plan, waiting for its next
 * invoice: paid work agreed and delivered, at the price an admin entered.
 *
 * $source names what it is (ChargeSource::source()) and $sourceId the record
 * behind it, so the invoice line can say where it came from and billing can
 * mark it billed. The amount excludes VAT, in $currency.
 */
final readonly class PendingCharge
{
    public function __construct(
        public string $source,
        public string $sourceId,
        public string $organizationId,
        public string $description,
        public string $amount,
        public string $currency,
        public string $reference,
    ) {}
}
