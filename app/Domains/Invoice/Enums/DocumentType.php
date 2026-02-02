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
     * Get Arabic label.
     */
    public function getLabelAr(): string
    {
        return match ($this) {
            self::Invoice => 'فاتورة ضريبية',
            self::CreditNote => 'إشعار دائن',
            self::DebitNote => 'إشعار مدين',
            self::Prepayment => 'فاتورة دفعة مقدمة',
            self::Proforma => 'فاتورة مبدئية',
        };
    }

    /**
     * Get English label.
     */
    public function getLabelEn(): string
    {
        return match ($this) {
            self::Invoice => 'Tax Invoice',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
            self::Prepayment => 'Prepayment Invoice',
            self::Proforma => 'Proforma Invoice',
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
     * Check if this document type is valid for ZATCA VAT reporting.
     *
     * Note: Proforma invoices CANNOT be submitted to ZATCA.
     * They are internal documents only.
     */
    public function isValidForZatca(): bool
    {
        return $this !== self::Proforma;
    }

    /**
     * Check if this is a reversal document (reduces VAT).
     */
    public function isReversal(): bool
    {
        return $this === self::CreditNote;
    }

    /**
     * Check if this is a prepayment/deposit document.
     */
    public function isPrepayment(): bool
    {
        return $this === self::Prepayment;
    }

    /**
     * Check if this is a proforma (draft) document.
     * Proforma invoices CANNOT be reported to ZATCA.
     */
    public function isProforma(): bool
    {
        return $this === self::Proforma;
    }

    /**
     * Get adjustment reason code for credit/debit notes.
     * Per ZATCA: Must provide reason for corrections.
     */
    public function getDefaultAdjustmentReason(): ?string
    {
        return match ($this) {
            self::CreditNote => 'Goods or services return',
            self::DebitNote => 'Additional charge or price adjustment',
            default => null,
        };
    }
}
