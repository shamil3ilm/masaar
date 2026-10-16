<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * ZATCA document types.
 *
 * Maps to UBL InvoiceTypeCode values.
 *
 * Note: Proforma invoices are NOT valid for VAT reporting.
 * They must be converted to Tax Invoice before submission.
 */
enum DocumentType: string
{
    case Invoice = 'invoice';          // 388 - Tax Invoice
    case CreditNote = 'credit_note';   // 381 - Credit Note
    case DebitNote = 'debit_note';     // 383 - Debit Note
    case Prepayment = 'prepayment';    // 386 - Prepayment Invoice
    case Proforma = 'proforma';        // 325 - Proforma (NOT for VAT reporting)

    /**
     * Get UBL type code.
     */
    public function getTypeCode(): string
    {
        return match ($this) {
            self::Invoice => '388',
            self::CreditNote => '381',
            self::DebitNote => '383',
            self::Prepayment => '386',
            self::Proforma => '325',
        };
    }

    /**
     * Check if this requires a billing reference (original invoice).
     */
    public function requiresBillingReference(): bool
    {
        return in_array($this, [self::CreditNote, self::DebitNote], true);
    }

    /**
     * Check if this is a proforma (draft) document.
     * Proforma invoices CANNOT be reported to ZATCA.
     */
    public function isProforma(): bool
    {
        return $this === self::Proforma;
    }
}
