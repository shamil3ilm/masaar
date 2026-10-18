<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceCharge;
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

        // ZATCA never accepted it, so there is nothing on record to correct.
        if ($invoice->status === InvoiceStatus::Rejected) {
            throw new DomainException(__('A rejected invoice is not corrected by a note. Issue a corrected invoice instead.'));
        }

        $inclusive = (bool) $invoice->prices_include_vat;
        $credit = $note === DocumentType::CreditNote;

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
            'buyer_id' => (string) $invoice->buyer_id,
            'buyer_id_scheme' => (string) $invoice->buyer_id_scheme,
            'buyer_address' => (array) ($invoice->buyer_address ?? []),
            'discount_amount' => $credit ? $this->discountAsEntered($invoice, $inclusive) : null,
            // The note is the same kind of transaction as what it corrects:
            // an export's credit note is an export's too. Ticked boxes, and
            // an unticked one is absent, as the form sends it.
            ...array_map(
                fn (): string => '1',
                array_filter($invoice->only(['is_third_party', 'is_nominal', 'is_export', 'is_summary', 'is_self_billed'])),
            ),
            // The category and exemption go with the line: without them a
            // zero-rated or exempt line came back as standard-rated at 0%,
            // which is not what the original said and not a valid line.
            'lines' => $credit
                ? $invoice->lines->map(fn ($line): array => [
                    'description' => (string) $line->description,
                    'description_ar' => (string) $line->description_ar,
                    'class_code' => (string) $line->class_code,
                    'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
                    'unit_price' => $line->priceAsEntered($inclusive),
                    'tax_rate' => (string) (float) $line->tax_rate,
                    'tax_category' => (string) $line->tax_category,
                    'exempt_code' => (string) $line->exempt_code,
                    'exempt_reason' => (string) $line->exempt_reason,
                    'unit_code' => (string) $line->unit_code,
                    // Its own discount and charges, as entered, where it has them.
                    ...($line->discount_amount === null ? [] : ['discount_amount' => (string) $line->discount_amount]),
                    ...($line->charges->isEmpty() ? [] : ['charges' => $line->charges->map(fn (InvoiceCharge $charge): array => $this->chargeAsEntered($charge))->values()->all()]),
                ])->values()->all()
                : [[]],
            // The charges on the whole invoice are credited with it.
            ...($credit && $invoice->charges->isNotEmpty() ? ['charges' => $invoice->charges->map(fn (InvoiceCharge $charge): array => [
                ...$this->chargeAsEntered($charge),
                'tax_category' => (string) $charge->tax_category,
                'tax_rate' => (string) (float) $charge->tax_rate,
                'exempt_code' => (string) $charge->exempt_code,
                'exempt_reason' => (string) $charge->exempt_reason,
            ])->values()->all()] : []),
        ];
    }

    /**
     * A charge as the form takes it: its percentage and base when it was
     * one, which price it again to the same amount, or its amount.
     *
     * @return array<string, string>
     */
    private function chargeAsEntered(InvoiceCharge $charge): array
    {
        return [
            'reason' => (string) $charge->reason,
            'reason_code' => (string) $charge->reason_code,
            ...($charge->percent !== null
                ? ['percent' => (string) (float) $charge->percent, 'base_amount' => (string) $charge->base_amount]
                : ['amount' => (string) $charge->amount]),
        ];
    }

    /**
     * The original's discount, as the form takes it.
     *
     * Crediting the whole invoice means crediting what was charged, which is
     * the lines less the discount; without it the note credited more than
     * the buyer paid. The stored discount is net. With VAT-inclusive prices
     * the form takes it gross, which is the lines' gross less the total.
     */
    private function discountAsEntered(Invoice $invoice, bool $inclusive): ?string
    {
        // The gross discount is what the lines and the charges, each gross,
        // came to over the total.
        $charges = $invoice->charges->reduce(fn (string $sum, InvoiceCharge $charge): string => bcadd($sum, (string) $charge->amount, 2), '0');
        $discount = $inclusive
            ? bcsub(bcadd($invoice->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->line_total, 2), '0'), $charges, 2), (string) $invoice->total, 2)
            : bcadd((string) $invoice->discount_amount, '0', 2);

        return bccomp($discount, '0', 2) > 0 ? $discount : null;
    }
}
