<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\DTOs;

/**
 * Peppol BIS Billing 3.0 invoice data for UAE FTA submission.
 */
final readonly class FtaInvoiceData
{
    public function __construct(
        // Invoice identification
        public string $invoiceNumber,
        public string $invoiceDate,           // YYYY-MM-DD
        public string $dueDate,               // YYYY-MM-DD
        public string $currencyCode,          // AED

        // Supplier (seller)
        public string $supplierName,
        public string $supplierTrn,           // 15-digit TRN
        public string $supplierStreet,
        public string $supplierCity,
        public string $supplierCountry,       // AE

        // Customer (buyer)
        public string $customerName,
        public ?string $customerTrn,          // nullable for B2C
        public string $customerStreet,
        public string $customerCity,
        public string $customerCountry,

        // Totals
        public float $lineExtensionAmount,    // sum of line net amounts
        public float $taxExclusiveAmount,     // subtotal excl. tax
        public float $taxInclusiveAmount,     // total incl. tax
        public float $payableAmount,

        // VAT
        public float $vatAmount,
        public float $vatRate,                // 0.05 for standard rate

        // Line items
        public array $lines,                  // array of FtaLineData

        // Document type
        public string $documentType,          // 380=invoice, 381=credit note, 383=debit note
        public ?string $creditNoteReference,  // for credit/debit notes
    ) {}
}
