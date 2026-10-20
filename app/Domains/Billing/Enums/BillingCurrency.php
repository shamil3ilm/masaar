<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * The currencies the platform bills its own customers in: Saudi riyals and
 * UAE dirhams, the customer's own.
 */
enum BillingCurrency: string
{
    case SAR = 'SAR';
    case AED = 'AED';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(fn (self $currency): string => $currency->value, self::cases());
    }
}
