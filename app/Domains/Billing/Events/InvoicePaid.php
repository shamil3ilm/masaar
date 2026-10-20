<?php

declare(strict_types=1);

namespace App\Domains\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An organization paid one of the platform's invoices: a plan payment
 * recorded today, and whatever invoicing records as paid later. What waits
 * on a customer paying - a referral that qualifies on its first paid
 * invoice - listens for this rather than reading payments itself.
 */
final class InvoicePaid
{
    use Dispatchable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $paymentId,
    ) {}
}
