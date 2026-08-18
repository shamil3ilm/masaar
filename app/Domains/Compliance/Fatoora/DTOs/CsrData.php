<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\DTOs;

use InvalidArgumentException;

/**
 * Data required for ZATCA CSR (Certificate Signing Request) generation.
 *
 * These values are tenant-supplied and are written into an OpenSSL
 * configuration file, which is an INI-style format. A value carrying a
 * newline ends the current directive and starts another, so an unvalidated
 * organization name can add a [section] or override a key and change the
 * extensions of the certificate request — producing a CSR that misrepresents
 * the taxpayer.
 *
 * Validation happens here, in the constructor, so no caller can assemble an
 * unchecked instance.
 */
final readonly class CsrData
{
    /**
     * Characters with meaning in an OpenSSL config file.
     *
     * `$` matters as much as the brackets: OpenSSL expands $var references
     * when reading a config.
     */
    private const FORBIDDEN = ["\n", "\r", "\0", '[', ']', '=', '#', ';', '$', '\\', '"'];

    private const MAX_LENGTH = 128;

    public function __construct(
        public string $organizationName,          // Company legal name
        public string $organizationUnit,          // Branch/department name
        public string $commonName,                // EGS serial number (device ID)
        public string $vatNumber,                 // 15-digit VAT registration number
        public string $serialNumber,              // Solution serial number
        public string $location,                  // Branch address
        public string $industry,                  // Business category
        public bool $invoiceTypesStandard = true, // Supports standard invoices
        public bool $invoiceTypesSimplified = true, // Supports simplified invoices
    ) {
        self::assertConfigSafe('organizationName', $organizationName);
        self::assertConfigSafe('organizationUnit', $organizationUnit);
        self::assertConfigSafe('commonName', $commonName);
        self::assertConfigSafe('serialNumber', $serialNumber);
        self::assertConfigSafe('location', $location);
        self::assertConfigSafe('industry', $industry);
        self::assertVatNumber($vatNumber);
    }

    /**
     * Reject anything that could alter the generated config's structure.
     */
    private static function assertConfigSafe(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("CSR field '{$field}' must not be empty.");
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                "CSR field '{$field}' exceeds ".self::MAX_LENGTH.' characters.'
            );
        }

        foreach (self::FORBIDDEN as $character) {
            if (str_contains($value, $character)) {
                throw new InvalidArgumentException(
                    "CSR field '{$field}' contains a character that is not permitted "
                    .'in a certificate request.'
                );
            }
        }

        // Control characters have no place in a distinguished name and would
        // survive into the certificate.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(
                "CSR field '{$field}' contains control characters."
            );
        }
    }

    /**
     * ZATCA requires exactly 15 digits, starting and ending with 3.
     */
    private static function assertVatNumber(string $vatNumber): void
    {
        if (preg_match('/^\d{15}$/', $vatNumber) !== 1) {
            throw new InvalidArgumentException(
                'CSR field \'vatNumber\' must be exactly 15 digits.'
            );
        }
    }

    /**
     * Get organization identifier in ZATCA format.
     * Format: VATSA-{15-digit VAT number}
     */
    public function getOrganizationIdentifier(): string
    {
        return 'VATSA-'.$this->vatNumber;
    }

    /**
     * Get invoice type code for CSR.
     * Bit flags: Standard=1, Simplified=2
     */
    public function getInvoiceTypeCode(): string
    {
        $code = 0;

        if ($this->invoiceTypesStandard) {
            $code |= 1;
        }
        if ($this->invoiceTypesSimplified) {
            $code |= 2;
        }

        return str_pad((string) $code, 4, '0', STR_PAD_LEFT);
    }
}
