<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * Where a payment came through, and so what reconciliation matches it
 * against: a bank account's statement, or the card gateway's settlement
 * report.
 */
enum PaymentChannel: string
{
    /** A transfer into one of the platform's bank accounts. */
    case Bank = 'bank';

    /** A card payment through the card gateway. */
    case Gateway = 'gateway';

    public function label(): string
    {
        return match ($this) {
            self::Bank => __('Bank'),
            self::Gateway => __('Card gateway'),
        };
    }
}
