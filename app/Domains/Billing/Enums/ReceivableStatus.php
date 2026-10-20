<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * Where an invoice to a customer stands: owed and not yet due, part paid,
 * paid, past its due date with something still owed, or written off.
 */
enum ReceivableStatus: string
{
    case Open = 'open';
    case PartlyPaid = 'partly_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::PartlyPaid => __('Partly paid'),
            self::Paid => __('Paid'),
            self::Overdue => __('Overdue'),
            self::WrittenOff => __('Written off'),
        };
    }

    /** Still owed: what an ageing and a reminder are about. */
    public function outstanding(): bool
    {
        return in_array($this, [self::Open, self::PartlyPaid, self::Overdue], true);
    }

    /** @return list<string> */
    public static function outstandingValues(): array
    {
        return [self::Open->value, self::PartlyPaid->value, self::Overdue->value];
    }
}
