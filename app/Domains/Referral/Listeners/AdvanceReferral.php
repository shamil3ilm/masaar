<?php

declare(strict_types=1);

namespace App\Domains\Referral\Listeners;

use App\Domains\Billing\Events\InvoicePaid;
use App\Domains\Organization\Events\OrganizationApproved;
use App\Domains\Referral\Services\Referrals;
use Throwable;

/**
 * A referred company was approved, or paid an invoice: its referral moves
 * on, and may qualify.
 *
 * Never fatal to what happened: the approval or the payment is committed
 * already, and a referral that could not move is reported and moved by an
 * admin.
 */
final class AdvanceReferral
{
    public function __construct(
        private readonly Referrals $referrals,
    ) {}

    public function handle(OrganizationApproved|InvoicePaid $event): void
    {
        try {
            $event instanceof OrganizationApproved
                ? $this->referrals->organizationApproved($event->organizationId, $event->adminId)
                : $this->referrals->invoicePaid($event->organizationId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
