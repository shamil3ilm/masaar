<?php

declare(strict_types=1);

namespace App\Domains\Referral\Listeners;

use App\Domains\Billing\Events\PaymentReversed;
use App\Domains\Referral\Services\Referrals;
use Throwable;

/**
 * A referred company's payment was reversed and it has no paid invoice
 * left: a referral that qualified on its first paid invoice is taken back,
 * or flagged for an admin (Referrals::paymentReversed).
 *
 * Never fatal to the reversal: the money is already put right, and a
 * referral that could not move is reported.
 */
final class TakeBackReferral
{
    public function __construct(
        private readonly Referrals $referrals,
    ) {}

    public function handle(PaymentReversed $event): void
    {
        if ($event->paidInvoiceLeft) {
            return;
        }

        try {
            $this->referrals->paymentReversed($event->organizationId, $event->actorId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
