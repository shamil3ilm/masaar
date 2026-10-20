<?php

declare(strict_types=1);

namespace App\Domains\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment recorded in error was reversed (Payments::reverse) - a
 * reconciliation match undone - and the invoices it paid owe it again.
 * paidInvoiceLeft says whether the organization still has a platform
 * invoice paid in full: without one, what waited on its first paid
 * invoice - a referral - no longer has it, and listens for this.
 */
final class PaymentReversed
{
    use Dispatchable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $paymentId,
        public readonly string $reason,
        public readonly ?string $actorId,
        public readonly bool $paidInvoiceLeft,
    ) {}
}
