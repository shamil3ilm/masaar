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
        public float $prepaidAmount = 0.0,
        public array $taxSubtotals = [],
        public ?string $paymentMeansCode = null,
        public ?string $paymentTerms = null,
        public ?string $previousInvoiceHash = null,
        public ?string $billingReferenceId = null,
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
     * Get invoice type name attribute.
     * Format: TNNNNNN where T=transaction type, N=subtypes
     */
    public function getInvoiceTypeName(): string
    {
        $type = $this->isSimplified() ? '02' : '01';

        return $type . '00000';
    }
}
