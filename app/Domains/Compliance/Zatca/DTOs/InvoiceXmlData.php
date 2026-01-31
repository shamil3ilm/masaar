<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\DTOs;

/**
 * Complete data required for ZATCA invoice XML generation.
 *
 * Includes all mandatory fields for Phase 2 compliance.
 */
final readonly class InvoiceXmlData
{
    /**
     * @param array<int, array{
     *   description: string,
     *   quantity: float,
     *   unitPrice: float,
     *   taxRate: float,
     *   taxAmount: float,
     *   lineTotal: float,
     *   taxCategory: string
     * }> $lines
     */
    public function __construct(
        // Required fields first
        public string $uuid,
        public string $invoiceNumber,
        public int $icv,
        public string $issueDate,
        public string $issueTime,
        public string $invoiceTypeCode,
        public string $invoiceSubtype,
        public string $currency,
        public string $sellerName,
        public string $sellerVatNumber,
        public AddressData $sellerAddress,
        public string $buyerName,
        public float $subtotal,
        public float $taxAmount,
        public float $total,
        public array $lines,
        // Optional fields with defaults
        public ?string $supplyDate = null,
        public ?string $sellerCrNumber = null,
        public ?string $buyerVatNumber = null,
        public ?AddressData $buyerAddress = null,
        public float $discount = 0.0,
        public ?string $discountReason = null,
        public ?string $discountTaxCategory = null,
        public ?float $discountTaxRate = null,
        public float $prepaidAmount = 0.0,
        public array $taxSubtotals = [],
        public ?string $paymentMeansCode = null,
        public ?string $paymentTerms = null,
        public ?string $previousInvoiceHash = null,
        public ?string $billingReferenceId = null,
        // Invoice type sub-flags (bits 3-7 per ZATCA specification)
        public bool $isThirdParty = false,    // Bit 3: Third party invoice
        public bool $isNominal = false,       // Bit 4: Nominal invoice
        public bool $isExport = false,        // Bit 5: Export invoice
        public bool $isSummary = false,       // Bit 6: Summary invoice
        public bool $isSelfBilled = false,    // Bit 7: Self-billed invoice
    ) {}

    /**
     * Check if this is a standard (B2B) invoice.
     */
    public function isStandard(): bool
    {
        return $this->invoiceSubtype === '01';
    }

    /**
     * Check if this is a simplified (B2C) invoice.
     */
    public function isSimplified(): bool
    {
        return $this->invoiceSubtype === '02';
    }

    /**
     * Check if this is a credit note.
     */
    public function isCreditNote(): bool
    {
        return $this->invoiceTypeCode === '381';
    }

    /**
     * Check if this is a debit note.
     */
    public function isDebitNote(): bool
    {
        return $this->invoiceTypeCode === '383';
    }

    /**
     * Get invoice type name attribute per ZATCA specification.
     *
     * Format: TTNNNNN where:
     * - TT (bits 1-2): Transaction type (01 = Standard, 02 = Simplified)
     * - N (bit 3): Third party invoice (0 or 1)
     * - N (bit 4): Nominal invoice (0 or 1)
     * - N (bit 5): Export invoice (0 or 1)
     * - N (bit 6): Summary invoice (0 or 1)
     * - N (bit 7): Self-billed invoice (0 or 1)
     *
     * Example: "0100000" = Standard invoice with no special flags
     * Example: "0200100" = Simplified export invoice
     */
    public function getInvoiceTypeName(): string
    {
        // Bits 1-2: Transaction type
        $transactionType = $this->isSimplified() ? '02' : '01';

        // Bits 3-7: Sub-type flags
        $bit3 = $this->isThirdParty ? '1' : '0';
        $bit4 = $this->isNominal ? '1' : '0';
        $bit5 = $this->isExport ? '1' : '0';
        $bit6 = $this->isSummary ? '1' : '0';
        $bit7 = $this->isSelfBilled ? '1' : '0';

        return $transactionType . $bit3 . $bit4 . $bit5 . $bit6 . $bit7;
    }

    /**
     * Check if this is a third-party invoice.
     */
    public function isThirdPartyInvoice(): bool
    {
        return $this->isThirdParty;
    }

    /**
     * Check if this is an export invoice.
     */
    public function isExportInvoice(): bool
    {
        return $this->isExport;
    }

    /**
     * Check if this is a self-billed invoice.
     */
    public function isSelfBilledInvoice(): bool
    {
        return $this->isSelfBilled;
    }
}
