<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Templates;

use App\Domains\Compliance\Engine\DTOs\PrintLabel;
use App\Domains\Compliance\Engine\DTOs\Receipt;

/**
 * A country's receipt with the organization's template laid on it: its
 * language order, its header under the title, and its terms, bank details
 * and footer after the totals.
 *
 * Only what the template adds. Every field the country put on the receipt
 * stays, in its order; the receipt stays black on white, whatever colour the
 * template has, because a thermal head prints a dot or none.
 */
final class TemplatedReceipt
{
    public static function apply(Receipt $receipt, InvoiceTemplate $template): Receipt
    {
        $text = fn (PrintLabel $label): array => array_column($template->lines($label), 'text');

        $terms = $text($template->terms);
        $bank = array_map(fn (array $row): string => $template->caption($row['label']).': '.$row['value'], $template->bankRows());

        return new Receipt(
            title: $receipt->title,
            sections: $receipt->sections,
            quantityLabel: $receipt->quantityLabel,
            unitPriceLabel: $receipt->unitPriceLabel,
            totalLabel: $receipt->totalLabel,
            items: $receipt->items,
            itemsNote: $receipt->itemsNote,
            totals: $receipt->totals,
            notes: $receipt->notes,
            qrPayload: $receipt->qrPayload,
            footer: $receipt->footer,
            arabicFirst: $template->arabicFirst,
            header: $text($template->header),
            closing: [
                ...($terms === [] ? [] : [$template->caption(new PrintLabel('Terms and conditions', 'الشروط والأحكام')), ...$terms]),
                ...$bank,
                ...$text($template->footer),
            ],
        );
    }
}
