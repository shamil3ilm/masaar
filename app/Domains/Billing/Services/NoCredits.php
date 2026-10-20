<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Contracts\CreditSource;
use DomainException;

/**
 * No credit source yet: what invoicing asks until the referral programme
 * binds its own (BillingServiceProvider binds this only if nothing else is
 * bound).
 */
final class NoCredits implements CreditSource
{
    public function available(string $organizationId, string $currency): array
    {
        return [];
    }

    public function redeem(string $creditId, string $invoiceId, string $amount): void
    {
        throw new DomainException('There is no credit '.$creditId.'.');
    }
}
