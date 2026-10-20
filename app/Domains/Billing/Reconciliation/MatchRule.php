<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation;

/**
 * How a match was found, strongest first: a reference the payer gave (an
 * invoice number, a payment's reference, the gateway's transaction id);
 * the amount on a date near enough; several invoices or several lines
 * adding up; a part payment; or an admin's own.
 */
enum MatchRule: string
{
    case Reference = 'reference';
    case Amount = 'amount';
    case Combined = 'combined';
    case Partial = 'partial';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Reference => __('By reference'),
            self::Amount => __('By amount and date'),
            self::Combined => __('Adds up across several'),
            self::Partial => __('Part payment'),
            self::Manual => __('Matched by hand'),
        };
    }
}
