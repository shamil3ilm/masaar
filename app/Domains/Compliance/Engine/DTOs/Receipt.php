<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * An issued invoice as a till prints it: one narrow column, every field the
 * country requires on a printed copy, in the order printed.
 *
 * The country decides what is on it (Presentation::receipt()); the roll
 * layouts - the browser's print view, the PDF and the printer image - only
 * draw it, so the three cannot disagree about what a receipt carries.
 */
final readonly class Receipt
{
    /**
     * @param  list<ReceiptSection>  $sections  the seller, the document, the buyer
     * @param  list<ReceiptItem>  $items
     * @param  PrintLabel|null  $itemsNote  what the line amounts include
     * @param  list<ReceiptRow>  $totals  in order, the amount due among them
     * @param  string  $qrPayload  the country's QR code, as stored; empty for none
     * @param  list<string>  $footer  small print at the foot
     * @param  bool  $arabicFirst  laid out right to left: each Arabic caption
     *                             leads, amounts sit at the left
     * @param  list<string>  $header  the organization's own lines under the title (its invoice template's)
     * @param  list<string>  $closing  the organization's own lines after the totals: its terms, bank details, a closing note
     */
    public function __construct(
        public PrintLabel $title,
        public array $sections,
        public PrintLabel $quantityLabel,
        public PrintLabel $unitPriceLabel,
        public PrintLabel $totalLabel,
        public array $items,
        public ?PrintLabel $itemsNote,
        public array $totals,
        public ?ReceiptRow $notes,
        public string $qrPayload,
        public array $footer = [],
        public bool $arabicFirst = false,
        public array $header = [],
        public array $closing = [],
    ) {}
}
