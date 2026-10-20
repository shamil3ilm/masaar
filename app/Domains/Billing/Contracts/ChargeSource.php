<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts;

use App\Domains\Billing\DTOs\PendingCharge;

/**
 * Where an organization's next invoice finds what it owes beyond its plan.
 *
 * Invoicing asks each source for the organization's unbilled charges in the
 * invoice's currency, puts each on the invoice as a line, and once the
 * invoice is issued tells the source which invoice billed it - so nothing
 * is billed twice and every charge can be traced to its invoice. Nothing
 * here prices anything: an admin agreed each amount.
 */
interface ChargeSource
{
    /** What this source's charges are, as PendingCharge::$source names it. */
    public function source(): string;

    /**
     * @return list<PendingCharge>
     */
    public function unbilled(string $organizationId, string $currency): array;

    /**
     * The charge was billed on this invoice.
     *
     * @throws \DomainException when it is not this source's, or was billed already
     */
    public function markBilled(string $sourceId, string $invoiceId): void;
}
