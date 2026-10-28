<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Engine\Contracts\Adjustments as AdjustmentsPort;
use App\Domains\Invoice\Enums\AdjustmentKind;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\AdjustmentDraft;
use App\Domains\Invoice\Support\AdjustmentReason;
use DomainException;

/**
 * Returns, exchanges and corrections on a Saudi invoice.
 *
 *   KSA VAT Implementing Regulations Art 40(1): the value of a supply is
 *   adjusted where it is cancelled or terminated in whole or in part (a),
 *   its nature materially changes so the tax changes (b), its agreed
 *   consideration is altered, "including due to an offer of an additional
 *   discount after the sale was made" (c), or goods are returned and the
 *   supplier accepts them (d). Art 40(3)-(5): the supplier adjusts its
 *   output tax by the tax on the change in consideration, an increase in
 *   the period the event occurred, a decrease in that period or the one
 *   the credit note is issued in, whichever is later.
 *
 *   Art 54(3), with Art 53: a credit or debit note states what a tax
 *   invoice does, plus the original's serial number (billing reference,
 *   BT-25 - BR-KSA-56 requires it on every note) and the reason; KSA-10
 *   lists the reasons in the authority's words and BR-KSA-17 requires one
 *   (AdjustmentReason).
 *
 *   ZATCA E-Invoicing Detailed Guidelines 4.3: "The type of credit/debit
 *   note follows the type of invoice that they are issued against" - a
 *   standard invoice's note is standard, a simplified one's simplified -
 *   and a note adjusts an invoice on the authority's record: cleared or
 *   reported (InvoiceStatus::Accepted). VatPeriodTracker checks both again
 *   when the note is issued, with the cap on what may be credited.
 *
 * An increase is a debit note (Art 54(1)). Each kind of event gives the
 * KSA-10 reason that names it: a return - in full, in part or for an
 * exchange - is "goods or services refund" (Art 40(1)(d)); a discount for
 * damaged goods kept is an amendment of the agreed value (c); an item
 * replaced by a different one at another price is an essential change in
 * the supply that changes the tax due (b); a corrected buyer is KSA-10's
 * change in the buyer's information.
 */
class Adjustments implements AdjustmentsPort
{
    private const REASONS = [
        'refund' => 'In case of goods or services refund.',
        'value' => 'Amendment of the supply value which is pre-agreed upon between the supplier and consumer',
        'change' => 'In case of essential change or amendment in the supply, which leads to the change of the VAT due',
        'buyer' => "In case of change in Seller's or Buyer's information",
    ];

    public function __construct(
        private readonly AdjustmentDraft $drafts,
    ) {}

    public function refusal(Invoice $original): ?string
    {
        if (in_array($original->document_type, [DocumentType::CreditNote, DocumentType::DebitNote], true)) {
            return __('A note corrects an invoice, not another note.');
        }

        if ($original->status === InvoiceStatus::Rejected) {
            return __('A rejected invoice is not corrected by a note. Issue a corrected invoice instead.');
        }

        if ($original->status !== InvoiceStatus::Accepted) {
            return $original->type?->value === 'standard'
                ? __('A note adjusts an invoice ZATCA has accepted: wait until this invoice is cleared (E-Invoicing Detailed Guidelines 4.3).')
                : __('A note adjusts an invoice ZATCA has accepted: wait until this invoice is reported (E-Invoicing Detailed Guidelines 4.3).');
        }

        return null;
    }

    public function increase(): DocumentType
    {
        return DocumentType::DebitNote;
    }

    public function document(Invoice $original, DocumentType $type, AdjustmentKind $kind, ?array $lines, string $issueDate, array $buyer = []): array
    {
        // The original as the new-invoice form takes it: its type, currency,
        // buyer and flags (a simplified invoice's note is simplified, an
        // export's an export's), its lines with their discount and charges.
        $fields = $this->drafts->fields($original, DocumentType::CreditNote);
        $note = $type->requiresBillingReference();

        $fields = [
            ...$fields,
            'document_type' => $type->value,
            'issue_date' => $issueDate,
            'billing_ref' => $note ? (string) $original->invoice_number : null,
            'adjustment_reason' => $note ? AdjustmentReason::bilingual(self::REASONS[$this->reason($kind)]) : null,
            ...array_intersect_key($buyer, array_flip(['buyer_name', 'buyer_name_ar', 'buyer_vat_number', 'buyer_id', 'buyer_id_scheme', 'buyer_address'])),
        ];

        if ($lines !== null) {
            unset($fields['charges']);
            $fields['discount_amount'] = null;
            $fields['prices_include_vat'] = '0';
            $fields['lines'] = array_map(fn (array $line): array => array_filter([
                'description' => $line['description'],
                'description_ar' => $line['description_ar'] ?? null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'unit_code' => $line['unit_code'] ?? null,
                'tax_category' => $line['tax_category'],
                'tax_rate' => $line['tax_rate'],
                'exempt_code' => $line['exempt_code'] ?? null,
                'exempt_reason' => $line['exempt_reason'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== ''), $lines);
        }

        return $fields;
    }

    private function reason(AdjustmentKind $kind): string
    {
        return match ($kind) {
            AdjustmentKind::Return, AdjustmentKind::PartialReturn, AdjustmentKind::Exchange => 'refund',
            AdjustmentKind::Discount => 'value',
            AdjustmentKind::PriceDifference => 'change',
            AdjustmentKind::BuyerCorrection => 'buyer',
            AdjustmentKind::Replacement, AdjustmentKind::BadDebtRecovery => throw new DomainException(__('This needs no credit or debit note.')),
        };
    }
}
