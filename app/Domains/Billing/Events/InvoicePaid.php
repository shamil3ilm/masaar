<?php

declare(strict_types=1);

namespace App\Domains\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An organization paid one of the platform's invoices in full: dispatched
 * once per invoice, by Receivables::settle when the money set against it
 * leaves nothing owed - a plan payment's invoice included. What waits
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
