<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * What happened to an issued invoice after it was issued (InvoiceAdjustments).
 *
 * The events the VAT laws adjust a supply for: it is returned wholly or in
 * part, its consideration is changed - a discount for damaged goods the
 * customer keeps - or its nature changes, as when an item is replaced by
 * another (KSA VAT Implementing Regulations Art 40(1)(a)-(d); UAE Federal
 * Decree-Law No. 8 of 2017 Art 61(1)(a)-(e)); and two that are not about
 * value: a faulty item replaced free by the same item, which changes no
 * tax, and the buyer's details corrected, which KSA-10 lists as a reason
 * of its own. A payment received after bad-debt relief (KSA Art 40(9)) is
 * recorded the same way, against the invoice relieved.
 */
enum AdjustmentKind: string
{
    case Return = 'return';
    case PartialReturn = 'partial_return';
    case Discount = 'discount';
    case Exchange = 'exchange';
    case PriceDifference = 'price_difference';
    case Replacement = 'replacement';
    case BuyerCorrection = 'buyer_correction';
    case BadDebtRecovery = 'bad_debt_recovery';

    /** The kinds a person or an integration asks for; a recovery follows a payment. */
    public static function requested(): array
    {
        return array_values(array_filter(self::cases(), fn (self $kind): bool => $kind !== self::BadDebtRecovery));
    }

    public function label(): string
    {
        return match ($this) {
            self::Return => __('Full return'),
            self::PartialReturn => __('Partial return'),
            self::Discount => __('Damaged, kept at a discount'),
            self::Exchange => __('Exchange for other items'),
            self::PriceDifference => __('Replaced by an item at another price'),
            self::Replacement => __('Replaced free by the same item'),
            self::BuyerCorrection => __('Buyer details corrected'),
            self::BadDebtRecovery => __('Paid after bad-debt relief'),
        };
    }

    /** Whether the customer gives back lines of the invoice, with quantities. */
    public function returnsLines(): bool
    {
        return in_array($this, [self::PartialReturn, self::Exchange, self::PriceDifference, self::Replacement], true);
    }

    /** Whether new items are given in their place. */
    public function replaces(): bool
    {
        return in_array($this, [self::Exchange, self::PriceDifference], true);
    }
}
