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

    /**
     * Determine category from exemption code.
     */
    public static function fromExemptionCode(string $code): self
    {
        return match (true) {
            // Out of scope - services outside KSA
            str_starts_with($code, 'VATEX-SA-OOS') => self::OutOfScope,

            // Zero-rated categories
            str_starts_with($code, 'VATEX-SA-29-7'),  // International transport
            str_starts_with($code, 'VATEX-SA-36'),    // Qualifying metals
            str_starts_with($code, 'VATEX-SA-HEA'),   // Healthcare
            str_starts_with($code, 'VATEX-SA-EDU')    // Education
                => self::ZeroRated,

            // Exempt categories
            str_starts_with($code, 'VATEX-SA-32'),    // Life insurance
            str_starts_with($code, 'VATEX-SA-33'),    // Real estate
            str_starts_with($code, 'VATEX-SA-34-1'),  // Financial services
            str_starts_with($code, 'VATEX-SA-34-2'),  // Employee benefits
            str_starts_with($code, 'VATEX-SA-34-3'),  // Local passenger transport
            str_starts_with($code, 'VATEX-SA-34-4'),  // Property rental
            str_starts_with($code, 'VATEX-SA-34-5'),  // Qualifying education
            str_starts_with($code, 'VATEX-SA-')       // Other exemptions
                => self::Exempt,

            default => self::Standard,
        };
    }
}
