<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\DTOs;

/**
 * Complete data required for ZATCA invoice XML generation.
 *
 * Includes all mandatory fields for Phase 2 compliance.
 *
 * Supports edge cases:
 * - Non-VAT buyer identification (TIN, CRN, NAT, IQA, PAS, GCC, MOM, MLS, OTH)
 * - Free goods / promotional items (market value for deemed supply)
 * - Deposit/prepayment invoice linking
 * - Multi-currency with exchange rates
 * - Bundle/composite discount allocation
 *
 * @see https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines
 */
final readonly class InvoiceXmlData
{
    /**
     * Valid buyer identification schemes per ZATCA.
     * Used when buyer is NOT VAT-registered.
     */
    public const BUYER_ID_SCHEMES = [
        'TIN' => 'Tax Identification Number',
        'CRN' => 'Commercial Registration Number',
        'MOM' => 'Momra License',
        'MLS' => 'MLSD License',
        'SAG' => 'Sagia License',
        'NAT' => 'National ID (Saudis)',
        'GCC' => 'GCC ID',
        'IQA' => 'Iqama Number',
        'PAS' => 'Passport Number',
        'OTH' => 'Other ID',
    ];

    /**
     * @param array<int, array{
     *   description: string,
     *   quantity: float,
     *   unitPrice: float,
     *   taxRate: float,
     *   taxAmount: float,
     *   lineTotal: float,
     *   taxCategory: string,
     *   taxExemptionReasonCode?: string,
     *   taxExemptionReason?: string,
     *   unitCode?: string,
     *   discount?: float,
     *   discountReason?: string,
     *   itemClassificationCode?: string,
     *   isFreeItem?: bool,
     *   marketValue?: float
     * }> $lines
     * @param array<int, string> $prepaymentInvoiceIds References to deposit/prepayment invoices
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
        public ?string $creditDebitReason = null,  // KSA-10: Required for credit/debit notes (BR-KSA-17)
        // Invoice type sub-flags (bits 3-7 per ZATCA specification)
        public bool $isThirdParty = false,    // Bit 3: Third party invoice
        public bool $isNominal = false,       // Bit 4: Nominal invoice
        public bool $isExport = false,        // Bit 5: Export invoice
        public bool $isSummary = false,       // Bit 6: Summary invoice
        public bool $isSelfBilled = false,    // Bit 7: Self-billed invoice
        // Tax-inclusive pricing flag
        public bool $isTaxInclusive = false,  // If true, unitPrice includes VAT and needs conversion
        // === NEW: Buyer identification for non-VAT registered buyers ===
        // Per ZATCA: When buyer has no VAT, must provide alternative ID
        public ?string $buyerIdScheme = null,  // One of: TIN, CRN, MOM, MLS, SAG, NAT, GCC, IQA, PAS, OTH
        public ?string $buyerId = null,        // The actual ID value
        // === NEW: Prepayment/deposit invoice references ===
        // Links final invoice to original deposit invoices for audit trail
        public array $prepaymentInvoiceIds = [],
        // === NEW: Multi-currency support ===
        // VAT must be in SAR; these fields support foreign currency display
        public ?string $originalCurrency = null,    // Original foreign currency code (e.g., USD, EUR)
        public ?float $exchangeRate = null,         // Exchange rate to SAR (e.g., 3.75 for USD)
        public ?string $exchangeRateDate = null,    // Date of exchange rate (YYYY-MM-DD)
        // === NEW: Special invoice flags ===
        public bool $isProforma = false,      // Proforma invoices excluded from VAT reporting
        public bool $isInterBranch = false,   // Internal transfer between branches (still taxable)
        public bool $isConsignment = false,   // Drop-ship / consignment
        public bool $isDeferredVat = false,   // Deferred VAT scheme (metadata only - actual handling is manual)
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

    /**
     * Calculate net price from gross (tax-inclusive) price.
     *
     * Formula: Net = Gross / (1 + taxRate/100)
     *
     * @param float $grossPrice Tax-inclusive price
     * @param float $taxRate Tax rate percentage (e.g., 15 for 15%)
     * @return float Net (tax-exclusive) price
     */
    public static function calculateNetFromGross(float $grossPrice, float $taxRate): float
    {
        if ($taxRate <= 0) {
            return $grossPrice;
        }

        return round($grossPrice / (1 + $taxRate / 100), 2);
    }

    /**
     * Calculate tax amount from gross (tax-inclusive) price.
     *
     * Formula: Tax = Gross - Net = Gross - (Gross / (1 + taxRate/100))
     *
     * @param float $grossPrice Tax-inclusive price
     * @param float $taxRate Tax rate percentage (e.g., 15 for 15%)
     * @return float Tax amount
     */
    public static function calculateTaxFromGross(float $grossPrice, float $taxRate): float
    {
        if ($taxRate <= 0) {
            return 0.0;
        }

        $netPrice = self::calculateNetFromGross($grossPrice, $taxRate);

        return round($grossPrice - $netPrice, 2);
    }

    /**
     * Calculate gross (tax-inclusive) price from net price.
     *
     * Formula: Gross = Net × (1 + taxRate/100)
     *
     * @param float $netPrice Tax-exclusive price
     * @param float $taxRate Tax rate percentage (e.g., 15 for 15%)
     * @return float Gross (tax-inclusive) price
     */
    public static function calculateGrossFromNet(float $netPrice, float $taxRate): float
    {
        return round($netPrice * (1 + $taxRate / 100), 2);
    }

    /**
     * Calculate tax amount from net (tax-exclusive) price.
     *
     * Formula: Tax = Net × (taxRate/100)
     *
     * @param float $netPrice Tax-exclusive price
     * @param float $taxRate Tax rate percentage (e.g., 15 for 15%)
     * @return float Tax amount
     */
    public static function calculateTaxFromNet(float $netPrice, float $taxRate): float
    {
        return round($netPrice * $taxRate / 100, 2);
    }

    /**
     * Check if buyer has VAT registration.
     */
    public function buyerHasVat(): bool
    {
        return $this->buyerVatNumber !== null && $this->buyerVatNumber !== '';
    }

    /**
     * Check if buyer has alternative identification (non-VAT).
     */
    public function buyerHasAlternativeId(): bool
    {
        return $this->buyerIdScheme !== null
            && $this->buyerId !== null
            && $this->buyerId !== '';
    }

    /**
     * Validate buyer identification scheme.
     */
    public function isValidBuyerIdScheme(): bool
    {
        if ($this->buyerIdScheme === null) {
            return true;
        }

        return array_key_exists($this->buyerIdScheme, self::BUYER_ID_SCHEMES);
    }

    /**
     * Check if this is a prepayment/deposit invoice.
     */
    public function isPrepayment(): bool
    {
        return $this->invoiceTypeCode === '386';
    }

    /**
     * Check if this invoice has prepayment references.
     */
    public function hasPrepaymentReferences(): bool
    {
        return ! empty($this->prepaymentInvoiceIds);
    }

    /**
     * Check if this is a multi-currency invoice.
     */
    public function isMultiCurrency(): bool
    {
        return $this->originalCurrency !== null
            && $this->originalCurrency !== $this->currency
            && $this->exchangeRate !== null;
    }

    /**
     * Convert amount from original currency to SAR.
     *
     * @param float $amount Amount in original currency
     * @return float Amount in SAR
     */
    public function convertToSar(float $amount): float
    {
        if (! $this->isMultiCurrency() || $this->exchangeRate === null) {
            return $amount;
        }

        return round($amount * $this->exchangeRate, 2);
    }

    /**
     * Check if invoice has free items requiring market value VAT.
     */
    public function hasFreeItems(): bool
    {
        foreach ($this->lines as $line) {
            if (isset($line['isFreeItem']) && $line['isFreeItem'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get effective price for a line item (market value for free items).
     *
     * Per ZATCA: Free goods require VAT on "deemed supply" at market value.
     *
     * @param array $line Line item data
     * @return float Price to use for VAT calculation
     */
    public static function getEffectivePrice(array $line): float
    {
        // If item is free and has market value, use market value for VAT
        if (isset($line['isFreeItem']) && $line['isFreeItem'] === true) {
            return $line['marketValue'] ?? 0.0;
        }

        return $line['unitPrice'] ?? 0.0;
    }

    /**
     * Allocate bundle discount proportionally across line items.
     *
     * Per ZATCA: Bundle discounts must be allocated by VAT rate to ensure
     * correct VAT calculation per line.
     *
     * @param array $lines Line items with subtotals
     * @param float $totalDiscount Total discount to allocate
     * @return array Lines with allocated discounts
     */
    public static function allocateBundleDiscount(array $lines, float $totalDiscount): array
    {
        if ($totalDiscount <= 0) {
            return $lines;
        }

        // Calculate total line value
        $totalLineValue = 0.0;
        foreach ($lines as $line) {
            $totalLineValue += ($line['unitPrice'] ?? 0) * ($line['quantity'] ?? 1);
        }

        if ($totalLineValue <= 0) {
            return $lines;
        }

        // Allocate discount proportionally
        $allocatedTotal = 0.0;
        $lastIndex = count($lines) - 1;

        foreach ($lines as $index => &$line) {
            $lineValue = ($line['unitPrice'] ?? 0) * ($line['quantity'] ?? 1);
            $proportion = $lineValue / $totalLineValue;

            if ($index === $lastIndex) {
                // Assign remainder to last line to avoid rounding errors
                $line['discount'] = round($totalDiscount - $allocatedTotal, 2);
            } else {
                $line['discount'] = round($totalDiscount * $proportion, 2);
                $allocatedTotal += $line['discount'];
            }

            $line['discountReason'] = $line['discountReason'] ?? 'Bundle discount allocation';
        }

        return $lines;
    }

    /**
     * Validate that credit note doesn't exceed original invoice.
     *
     * @param float $originalTotal Original invoice total
     * @param float $previousCnTotal Sum of previous credit notes for same invoice
     * @return bool True if valid
     */
    public function validateCreditNoteAmount(float $originalTotal, float $previousCnTotal = 0.0): bool
    {
        if (! $this->isCreditNote()) {
            return true;
        }

        $maxAllowed = $originalTotal - $previousCnTotal;

        return $this->total <= $maxAllowed;
    }
}
