<?php

declare(strict_types=1);

namespace App\Domains\Books\Enums;

/**
 * What a purchase line is for the VAT return.
 *
 *   standard        the supplier charged VAT at the standard rate: input VAT
 *                   (KSA VAT Implementing Regulations Art 49; UAE Federal
 *                   Decree-Law No. 8 of 2017 Art 54)
 *   zero            zero-rated by the supplier: no VAT, reported as such
 *   exempt          exempt: no VAT, reported as such
 *   out_of_scope    outside VAT altogether (wages, fines, a non-registered
 *                   supplier's sale): kept, and reported in no box
 *   import          goods imported, with VAT paid at customs before release:
 *                   input VAT evidenced by the customs declaration, and
 *                   nothing more due in the return (UAE Cabinet Decision
 *                   No. 52 of 2017 Art 50(1); KSA VAT IR Art 49)
 *   import_in_return goods imported with the VAT accounted for in the
 *                   return instead of at customs - the registrant's own
 *                   customs code (UAE Cabinet Decision No. 52 of 2017 Art
 *                   48(1), (4)(b): VAT 201 box 6), or ZATCA's deferral
 *                   ("Imports subject to VAT accounted for through the
 *                   reverse charge mechanism", box 9): assessed and
 *                   deducted, like a reverse charge
 *   reverse_charge  services from a supplier not resident in the country:
 *                   the organization assesses the VAT itself and deducts
 *                   it in the same return (KSA Art 47(1); GCC Unified VAT
 *                   Agreement Art 41(1); UAE Decree-Law Art 48(1))
 *   domestic_reverse_charge
 *                   goods a registered supplier in the country sells to a
 *                   registered buyer who accounts for the VAT itself - in the
 *                   UAE oil, gas and hydrocarbons (Decree-Law Art 48(3)-(5))
 *                   and goods the Cabinet specifies (Art 48(8)); offered
 *                   only where the country's return has them
 *                   (VatReturn::domesticReverseChargeGoods)
 */
enum PurchaseTreatment: string
{
    case Standard = 'standard';
    case Zero = 'zero';
    case Exempt = 'exempt';
    case OutOfScope = 'out_of_scope';
    case Import = 'import';
    case ImportInReturn = 'import_in_return';
    case ReverseCharge = 'reverse_charge';
    case DomesticReverseCharge = 'domestic_reverse_charge';

    public function label(): string
    {
        return match ($this) {
            self::Standard => __('Standard rated'),
            self::Zero => __('Zero rated'),
            self::Exempt => __('Exempt'),
            self::OutOfScope => __('Out of scope'),
            self::Import => __('Import, VAT paid at customs'),
            self::ImportInReturn => __('Import, VAT accounted for in the return'),
            self::ReverseCharge => __('Reverse charge (services from abroad)'),
            self::DomesticReverseCharge => __('Domestic reverse charge (specified goods)'),
        };
    }

    /** The supplier's invoice, or the customs declaration, carries VAT. */
    public function charged(): bool
    {
        return in_array($this, [self::Standard, self::Import], true);
    }

    /** VAT the organization assesses itself: due in the return, and deducted. */
    public function selfAssessed(): bool
    {
        return in_array($this, [self::ReverseCharge, self::ImportInReturn, self::DomesticReverseCharge], true);
    }

    /** VAT the organization may deduct: what it was charged, or assessed itself. */
    public function deductible(): bool
    {
        return $this->charged() || $this->selfAssessed();
    }

    /** A line with no VAT on it at all. */
    public function untaxed(): bool
    {
        return ! $this->deductible();
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
