<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use DomainException;

/**
 * A credit or debit note, started from the invoice it corrects.
 *
 * An issued invoice cannot change; a mistake on one is corrected by a note
 * that names it. Writing that note meant retyping the buyer, every line and
 * the invoice number it refers to - and a mistyped reference is a note ZATCA
 * cannot tie to anything. This fills the new-invoice form from the original;
 * what is left to type is the reason, and whatever differs.
 */
class AdjustmentDraft
{
    /**
     * The new-invoice form's fields, filled from the invoice being corrected.
     *
     * A credit note starts with the original's lines - most often the whole
     * invoice is being credited, and removing lines is easier than typing
     * them. A debit note adds a charge, so it starts with none.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException when the invoice cannot be corrected by a note
     */
    public function fields(Invoice $invoice, DocumentType $note): array
    {
        if (! in_array($note, [DocumentType::CreditNote, DocumentType::DebitNote], true)) {
            throw new DomainException(__('Only a credit or debit note corrects an invoice.'));
        }

        if ($invoice->hash === null) {
            throw new DomainException(__('A draft is corrected by editing it, not by a note.'));
        }

        if (in_array($invoice->document_type, [DocumentType::CreditNote, DocumentType::DebitNote], true)) {
            throw new DomainException(__('A note corrects an invoice, not another note.'));
        }

        $inclusive = (bool) $invoice->prices_include_vat;

        return [
            'type' => $invoice->type->value,
            'document_type' => $note->value,
            'billing_ref' => (string) $invoice->invoice_number,
            'currency' => (string) $invoice->currency,
            'exchange_rate' => $invoice->exchange_rate === null ? null : (string) (float) $invoice->exchange_rate,
            'prices_include_vat' => $inclusive ? '1' : '0',
            'buyer_name' => (string) $invoice->buyer_name,
            'buyer_name_ar' => (string) $invoice->buyer_name_ar,
            'buyer_vat_number' => (string) $invoice->buyer_vat_number,
            'buyer_address' => (array) ($invoice->buyer_address ?? []),
            'lines' => $note === DocumentType::CreditNote
                ? $invoice->lines->map(fn ($line): array => [
                    'description' => (string) $line->description,
                    'description_ar' => (string) $line->description_ar,
                    'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
                    'unit_price' => $line->priceAsEntered($inclusive),
                    'tax_rate' => (string) (float) $line->tax_rate,
                    'unit_code' => (string) $line->unit_code,
                ])->values()->all()
                : [[]],
        ];
    }
}
