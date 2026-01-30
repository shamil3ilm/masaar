<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * ZATCA document types.
 *
 * Maps to UBL InvoiceTypeCode values.
 */
enum DocumentType: string
{
    case Invoice = 'invoice';          // 388 - Tax Invoice
    case CreditNote = 'credit_note';   // 381 - Credit Note
    case DebitNote = 'debit_note';     // 383 - Debit Note
    case Prepayment = 'prepayment';    // 386 - Prepayment Invoice

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
        };
    }

    /**
     * Check if this requires a billing reference (original invoice).
     */
    public function requiresBillingReference(): bool
    {
        return in_array($this, [self::CreditNote, self::DebitNote], true);
    }
}
