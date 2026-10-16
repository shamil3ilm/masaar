<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * VAT category codes (UN/CEFACT 5305), as the authorities use them.
 *
 * ZATCA accepts S, Z, E and O only - see saudi(). PINT AE adds two:
 * AE, reverse charge, and N, "Standard rate additional VAT", which rule
 * IBR-116-AE requires on a margin-scheme invoice. The published code list
 * page prints N as a Greek capital Nu; the schematron compares against the
 * Latin N, and that is what validates.
 *
 * @see https://zatca.gov.sa/ar/E-Invoicing/Introduction/Guidelines/Documents/E-invoicing_Detailed_Technical_Guidelines.pdf
 * @see https://docs.peppol.eu/poac/ae/pint-ae/trn-invoice/codelist/Aligned-TaxCategoryCodes/
 * @see https://docs.peppol.eu/poac/ae/pint-ae/trn-invoice/rule/ibr-116-ae/
 */
enum TaxCategory: string
{
    case Standard = 'S';    // Standard rated (any positive VAT rate: 5%, 15%, etc.)
    case ZeroRated = 'Z';   // Zero rated (0% with specific exemption reason)
    case Exempt = 'E';      // Exempt from VAT (with exemption reason)
    case OutOfScope = 'O';  // Out of scope (services outside KSA)
    case ReverseCharge = 'AE';  // UAE: VAT levied from the buyer; the line carries none
    case MarginScheme = 'N';    // UAE: standard rate additional VAT, used for the margin scheme

    /**
     * The categories a Saudi invoice may use. ZATCA refuses any other.
     *
     * @return list<string>
     */
    public static function saudi(): array
    {
        return [self::Standard->value, self::ZeroRated->value, self::Exempt->value, self::OutOfScope->value];
    }

    /**
     * Get display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard Rated',
            self::ZeroRated => 'Zero Rated',
            self::Exempt => 'Exempt',
            self::OutOfScope => 'Out of Scope',
            self::ReverseCharge => 'Reverse Charge',
            self::MarginScheme => 'Margin Scheme',
        };
    }

    /**
     * Check if this category requires an exemption reason.
     */
    public function requiresExemptionReason(): bool
    {
        return match ($this) {
            self::ZeroRated, self::Exempt, self::OutOfScope => true,
            self::Standard, self::ReverseCharge, self::MarginScheme => false,
        };
    }

    /**
     * Get default tax rate for category.
     *
     * The two UAE categories state the UAE's standard rate while charging
     * no tax on the document (ALIGNED-IBRP-AE-09-AE, IBR-108-AE).
     */
    public function defaultTaxRate(): float
    {
        return match ($this) {
            self::Standard => 15.0,
            self::ZeroRated, self::Exempt, self::OutOfScope => 0.0,
            self::ReverseCharge, self::MarginScheme => 5.0,
        };
    }
}
