<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintDocumentCategory;
use App\Domains\Compliance\Engine\Contracts\Adjustments as AdjustmentsPort;
use App\Domains\Invoice\Enums\AdjustmentKind;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use DomainException;

/**
 * Returns, exchanges and corrections on a UAE invoice.
 *
 *   Federal Decree-Law No. 8 of 2017 Art 61(1): output tax is adjusted
 *   after the date of supply if the supply was cancelled (a), its tax
 *   treatment changed with a change in its nature (b), its agreed
 *   consideration was altered (c), the goods or services were returned and
 *   the consideration returned in whole or in part (d), or tax was charged
 *   or a treatment applied in error (e). Art 62: an increase is "a new Tax
 *   Invoice for the additional amount of Tax" (1); a decrease is a Tax
 *   Credit Note, issued within 14 days of the event (2).
 *
 *   Cabinet Decision No. 52 of 2017 Art 60(1): the Tax Credit Note says
 *   "Tax Credit Note", names both parties, its date, the value on the tax
 *   invoice, the correct value, the difference and its tax (e), "a brief
 *   explanation of the circumstances" (f) and enough to identify the
 *   supply (g) - the invoice it corrects, as the billing reference.
 *
 * PINT AE carries the explanation as a code (BTAE-03, the CreditReason
 * list): DL8.61.1.A to E, Art 61(1)(a) to (e). A return - in full, in part
 * or for an exchange - is D; a discount for damaged goods kept is C; an item
 * replaced by another at a lower price is B. The list has no code for a
 * buyer's details corrected: the invoice was issued to the buyer in error,
 * which is E, and the new invoice names the right buyer.
 *
 * A UAE e-invoice is never simplified and has no debit note
 * (InvoiceRules), so every document is a tax invoice or a tax credit note,
 * with the original's category, scenario flags, buyer endpoint and emirate.
 * An invoice that is still a draft, or was rejected, has nothing issued to
 * adjust; a self-billed one is the buyer's to correct.
 */
class Adjustments implements AdjustmentsPort
{
    public function refusal(Invoice $original): ?string
    {
        if ($original->document_type !== DocumentType::Invoice) {
            return __('A tax credit note corrects a tax invoice, not another note.');
        }

        if (in_array($original->status, [InvoiceStatus::Draft, InvoiceStatus::Rejected], true)) {
            return __('Only an issued invoice is corrected by a tax credit note.');
        }

        if ($this->category($original) === 'self_billing') {
            return __('A self-billed invoice is corrected by the buyer who issued it.');
        }

        return null;
    }

    public function increase(): DocumentType
    {
        return DocumentType::Invoice;
    }

    public function document(Invoice $original, DocumentType $type, AdjustmentKind $kind, ?array $lines, string $issueDate, array $buyer = []): array
    {
        $data = (array) ($original->jurisdiction_data ?? []);
        $credit = $type === DocumentType::CreditNote;
        $original->loadMissing('lines');
        $originalLines = $original->lines->values();
        $details = (array) ($data['lines'] ?? []);

        $lines ??= $originalLines->map(fn (InvoiceLine $line, int $index): array => [
            'position' => $index + 1,
            'description' => (string) $line->description,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'unit_code' => $line->unit_code,
            'tax_category' => (string) $line->tax_category,
            'tax_rate' => (string) (float) $line->tax_rate,
            'exempt_code' => $line->exempt_code,
            'exempt_reason' => $line->exempt_reason,
            'discount_amount' => $line->discount_amount === null ? null : (string) $line->discount_amount,
        ])->all();

        return array_filter([
            'document_type' => $credit ? DocumentType::CreditNote->value : DocumentType::Invoice->value,
            'document_category' => $this->category($original),
            'issue_date' => $issueDate,
            // IBR-127-AE: a credit note has none; a new invoice is due as the
            // original was, or on issue once that has passed.
            'due_date' => $credit ? null : max($issueDate, (string) $original->due_date?->toDateString()),
            'currency' => (string) $original->currency,
            'exchange_rate' => $original->exchange_rate === null ? null : (string) (float) $original->exchange_rate,
            'payment_means_code' => $original->payment_means_code,
            'buyer_name' => $buyer['buyer_name'] ?? $original->buyer_name,
            'buyer_name_ar' => $buyer['buyer_name_ar'] ?? $original->buyer_name_ar,
            'buyer_vat_number' => $buyer['buyer_vat_number'] ?? $original->buyer_vat_number,
            'buyer_address' => (array) ($buyer['buyer_address'] ?? $original->buyer_address ?? []),
            'buyer_endpoint' => $buyer['buyer_endpoint'] ?? $data['buyer_endpoint'] ?? null,
            'buyer_subdivision' => $buyer['buyer_subdivision'] ?? $data['buyer_subdivision'] ?? null,
            'buyer_legal_registration' => $data['buyer_legal_registration'] ?? null,
            'transaction_flags' => $data['transaction_flags'] ?? null,
            'beneficiary_id' => $data['beneficiary_id'] ?? null,
            'principal_id' => $data['principal_id'] ?? null,
            'contract_reference' => $original->contract_reference,
            'billing_ref' => $credit ? (string) $original->invoice_number : null,
            'credit_reason' => $credit ? $this->reason($kind) : null,
            'lines' => array_map(function (array $line) use ($originalLines, $details): array {
                $source = $line['position'] === null ? null : $originalLines[$line['position'] - 1] ?? null;
                $extra = $line['position'] === null ? [] : (array) ($details[(string) $line['position']] ?? []);

                return array_filter([
                    'item_name' => $source?->item_name ?: $line['description'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'unit_code' => $line['unit_code'] ?? null,
                    'tax_category' => $line['tax_category'],
                    'tax_rate' => $line['tax_rate'],
                    'exempt_code' => $line['exempt_code'] ?? null,
                    'exempt_reason' => $line['exempt_reason'] ?? null,
                    'discount_amount' => $line['discount_amount'] ?? null,
                    ...array_intersect_key($extra, array_flip(['goods_type', 'item_id', 'hs_code'])),
                ], fn ($value): bool => $value !== null && $value !== '');
            }, $lines),
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /** The request's category for the original's kind of document. */
    private function category(Invoice $original): string
    {
        $named = PintDocumentCategory::tryFrom((string) (((array) ($original->jurisdiction_data ?? []))['document_category'] ?? ''));

        return match (true) {
            $named?->isSelfBilled() === true || (bool) $original->is_self_billed => 'self_billing',
            $named?->isCommercial() === true => 'commercial',
            default => 'tax',
        };
    }

    private function reason(AdjustmentKind $kind): string
    {
        return match ($kind) {
            AdjustmentKind::Return, AdjustmentKind::PartialReturn, AdjustmentKind::Exchange => 'DL8.61.1.D',
            AdjustmentKind::Discount => 'DL8.61.1.C',
            AdjustmentKind::PriceDifference => 'DL8.61.1.B',
            AdjustmentKind::BuyerCorrection => 'DL8.61.1.E',
            AdjustmentKind::Replacement, AdjustmentKind::BadDebtRecovery => throw new DomainException(__('This needs no tax credit note.')),
        };
    }
}
