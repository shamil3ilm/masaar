<?php

declare(strict_types=1);

namespace App\Domains\Billing\Events;

use App\Domains\Billing\Models\Receivable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The platform invoiced a customer. For whatever follows an invoice - the
 * referral programme qualifying a referral on the first, say - to listen
 * for, rather than reach into Receivables.
 */
final class ReceivableIssued
{
    use Dispatchable;

    public function __construct(
        public readonly Receivable $receivable,
    ) {}
}
