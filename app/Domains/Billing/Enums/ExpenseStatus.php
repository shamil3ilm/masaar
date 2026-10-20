<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * Where an expense stands: a draft waiting for its bill (the recurring
 * costs make these), owed to its supplier, part paid, paid, written off,
 * or void - entered by mistake, kept, and counted nowhere.
 */
enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case PartlyPaid = 'partly_paid';
    case Paid = 'paid';
    case WrittenOff = 'written_off';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Unpaid => __('Unpaid'),
            self::PartlyPaid => __('Partly paid'),
            self::Paid => __('Paid'),
            self::WrittenOff => __('Written off'),
            self::Void => __('Void'),
        };
    }

    /** The colour its pill takes (x-ui.badge's states). */
    public function state(): string
    {
        return match ($this) {
            self::Draft => 'pending',
            self::Unpaid => 'open',
            self::Void => 'cancelled',
            default => $this->value,
        };
    }

    /** What the reports count: recorded and not void. */
    public function counted(): bool
    {
        return ! in_array($this, [self::Draft, self::Void], true);
    }

    /** @return list<string> */
    public static function countedValues(): array
    {
        return [self::Unpaid->value, self::PartlyPaid->value, self::Paid->value, self::WrittenOff->value];
    }

    /** Still owed to the supplier: what payables and their ageing are about. */
    public static function owedValues(): array
    {
        return [self::Unpaid->value, self::PartlyPaid->value];
    }
}
