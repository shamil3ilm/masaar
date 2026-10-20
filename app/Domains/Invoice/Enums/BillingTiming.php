<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * When a schedule bills a period: on its first day, ahead of the supply
 * (a term's fees, a subscription), or the day after its last, for what was
 * supplied (a month's service). Either way the invoice states the period
 * it bills as its supply period.
 */
enum BillingTiming: string
{
    case Advance = 'advance';
    case Arrears = 'arrears';

    public function label(): string
    {
        return match ($this) {
            self::Advance => __('In advance, on the period\'s first day'),
            self::Arrears => __('In arrears, the day after the period ends'),
        };
    }
}
