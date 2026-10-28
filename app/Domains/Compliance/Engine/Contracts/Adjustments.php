<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Invoice\Enums\AdjustmentKind;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;

/**
 * The documents a return, an exchange, a discount or a correction on an
 * issued invoice calls for (InvoiceAdjustments).
 *
 * Shared code works out what is returned, replaced or discounted, and links
 * the documents to the invoice; the country says when an invoice can be
 * adjusted at all, what an increase in value is issued as, why each note
 * says it was issued, and how each document is asked for in its own rules,
 * so the documents are checked by those rules (CountryDraft) as any other
 * invoice of the country is.
 */
interface Adjustments
{
    /**
     * Why this invoice cannot be adjusted now, in the reader's language and
     * citing the country's own documents; null when it can.
     */
    public function refusal(Invoice $original): ?string;

    /** What an increase in value is issued as: a debit note, or a new tax invoice. */
    public function increase(): DocumentType;

    /**
     * The request for one document arising from the original, as the
     * country's invoice rules take it: a credit or debit note naming the
     * original, with the country's reason for $kind, or a new invoice to the
     * same buyer.
     *
     * Each line has a description (and description_ar), quantity, net
     * unit_price, tax_category, tax_rate, exempt_code, exempt_reason,
     * unit_code, and the position of the original's line it is about, from
     * 1, or null for a new item. Null lines are the original's own lines,
     * discount and charges, as they were.
     *
     * @param  list<array<string, mixed>>|null  $lines
     * @param  array<string, mixed>  $buyer  buyer details replacing the original's, by field
     * @return array<string, mixed>
     */
    public function document(Invoice $original, DocumentType $type, AdjustmentKind $kind, ?array $lines, string $issueDate, array $buyer = []): array;
}
