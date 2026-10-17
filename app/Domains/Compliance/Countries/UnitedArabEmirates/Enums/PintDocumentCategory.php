<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Enums;

/**
 * The six kinds of UAE e-invoice (Electronic Invoicing Guidelines v1.1,
 * section 10.1).
 *
 * A tax invoice is issued for a taxable supply; a commercial one for a supply
 * that needs no tax invoice - exempt or out of scope, or by a seller not
 * registered for VAT. Self-billing exists for tax invoices only. There is no
 * debit note: an increase is a new invoice.
 */
enum PintDocumentCategory: string
{
    case TaxInvoice = 'tax_invoice';
    case TaxCreditNote = 'tax_credit_note';
    case CommercialInvoice = 'commercial_invoice';
    case CommercialCreditNote = 'commercial_credit_note';
    case SelfBilledInvoice = 'self_billed_invoice';
    case SelfBilledCreditNote = 'self_billed_credit_note';

    public function isCreditNote(): bool
    {
        return in_array($this, [self::TaxCreditNote, self::CommercialCreditNote, self::SelfBilledCreditNote], true);
    }

    public function isCommercial(): bool
    {
        return in_array($this, [self::CommercialInvoice, self::CommercialCreditNote], true);
    }

    /**
     * The buyer issues it on the supplier's behalf: the organization is the
     * buyer, and the invoice's counterparty is the seller.
     */
    public function isSelfBilled(): bool
    {
        return in_array($this, [self::SelfBilledInvoice, self::SelfBilledCreditNote], true);
    }

    /**
     * What peppol_documents.document_type records.
     */
    public function documentType(): string
    {
        return $this->isCreditNote() ? 'credit_note' : 'invoice';
    }
}
