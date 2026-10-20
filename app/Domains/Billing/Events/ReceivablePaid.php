<?php

declare(strict_types=1);

namespace App\Domains\Billing\Events;

use App\Domains\Billing\Models\Receivable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer's invoice is paid in full - by payments, credit or both. The
 * referral programme's "first paid invoice" is this, for one; a listener
 * runs after the payment is committed.
 */
final class ReceivablePaid
{
    use Dispatchable;

    public function __construct(
        public readonly Receivable $receivable,
    ) {}
}
