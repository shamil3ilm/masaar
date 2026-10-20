<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * How the platform paid a supplier, and so what a reconciliation of
 * outgoing payments matches it against: a bank account's statement, or the
 * card's statement.
 */
enum OutgoingChannel: string
{
    /** A transfer from one of the platform's bank accounts. */
    case Bank = 'bank';

    /** Paid with the platform's card. */
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Bank => __('Bank transfer'),
            self::Card => __('Card'),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [self::Bank->value => self::Bank->label(), self::Card->value => self::Card->label()];
    }
}
