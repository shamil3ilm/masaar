<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * ZATCA Tax Category codes.
 *
 * Based on UN/CEFACT code list 5305 (Duty/Tax/Fee category code).
 *
 * @see https://zatca.gov.sa/ar/E-Invoicing/Introduction/Guidelines/Documents/E-invoicing_Detailed_Technical_Guidelines.pdf
 */
enum TaxCategory: string
{
    case Standard = 'S';    // Standard rated (any positive VAT rate: 5%, 15%, etc.)
    case ZeroRated = 'Z';   // Zero rated (0% with specific exemption reason)
    case Exempt = 'E';      // Exempt from VAT (with exemption reason)
    case OutOfScope = 'O';  // Out of scope (services outside KSA)

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
        };
    }

    /**
     * Check if this category requires an exemption reason.
     */
    public function requiresExemptionReason(): bool
    {
        return match ($this) {
            self::ZeroRated, self::Exempt, self::OutOfScope => true,
            self::Standard => false,
        };
    }

    /**
     * Get default tax rate for category.
     */
    public function defaultTaxRate(): float
    {
        return match ($this) {
            self::Standard => 15.0,
            self::ZeroRated, self::Exempt, self::OutOfScope => 0.0,
        };
    }
}
