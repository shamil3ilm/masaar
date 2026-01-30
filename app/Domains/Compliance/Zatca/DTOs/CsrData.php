<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\DTOs;

/**
 * Data required for ZATCA CSR (Certificate Signing Request) generation.
 */
final readonly class CsrData
{
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
    ) {}

    /**
     * Get organization identifier in ZATCA format.
     * Format: VATSA-{15-digit VAT number}
     */
    public function getOrganizationIdentifier(): string
    {
        return 'VATSA-' . $this->vatNumber;
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
