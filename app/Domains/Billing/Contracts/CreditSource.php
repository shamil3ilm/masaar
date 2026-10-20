<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts;

use App\Domains\Billing\DTOs\AvailableCredit;

/**
 * Where an organization's next invoice finds the credit it holds: a sum in
 * the invoice's currency, or a percentage off it.
 *
 * Invoicing asks for the organization's available credit, applies what it
 * uses - a sum up to the invoice's total, a percentage once - and tells the
 * source which invoice redeemed each credit and for how much, so a credit
 * is used once and every reduction can be traced to what granted it. A
 * credit past its expiry is not offered.
 */
interface CreditSource
{
    /**
     * Credits in this currency, and percentages off, oldest first.
     *
     * @return list<AvailableCredit>
     */
    public function available(string $organizationId, string $currency): array;

    /**
     * The credit was used on this invoice, taking $amount off it.
     *
     * @throws \DomainException when it is not available, or $amount is more than it holds
     */
    public function redeem(string $creditId, string $invoiceId, string $amount): void;
}
