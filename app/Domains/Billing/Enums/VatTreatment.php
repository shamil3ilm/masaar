<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * What the platform's VAT return takes from an expense.
 *
 *   recoverable      the supplier charged VAT the platform deducts as input
 *                    tax (VAT Implementing Regulations Art 49(1))
 *   not_recoverable  the supplier charged VAT the platform cannot deduct -
 *                    it is part of the cost (Art 50, Art 49(7) without
 *                    evidence)
 *   no_vat           none was charged: exempt, out of scope, or a supplier
 *                    not registered
 *   reverse_charge   services from a supplier not resident where the
 *                    platform is taxed: the supplier charges none and the
 *                    platform accounts for it itself (Art 47(1))
 */
enum VatTreatment: string
{
    case Recoverable = 'recoverable';
    case NotRecoverable = 'not_recoverable';
    case NoVat = 'no_vat';
    case ReverseCharge = 'reverse_charge';

    public function label(): string
    {
        return match ($this) {
            self::Recoverable => __('Input VAT, recoverable'),
            self::NotRecoverable => __('Input VAT, not recoverable'),
            self::NoVat => __('No VAT charged'),
            self::ReverseCharge => __('Reverse charge (imported services)'),
        };
    }

    /** The supplier's own invoice carries VAT. */
    public function charged(): bool
    {
        return in_array($this, [self::Recoverable, self::NotRecoverable], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
