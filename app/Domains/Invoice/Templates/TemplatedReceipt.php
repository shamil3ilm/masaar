<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Templates;

use App\Domains\Compliance\Engine\DTOs\PrintLabel;
use App\Domains\Compliance\Engine\DTOs\Receipt;
use App\Domains\Compliance\Engine\DTOs\ReceiptItem;
use App\Domains\Compliance\Engine\DTOs\ReceiptRow;
use App\Domains\Compliance\Engine\DTOs\ReceiptSection;

/**
 * A country's receipt with the organization's template laid on it: its
 * language order, its header under the title, and its terms, bank details
 * and footer after the totals.
 *
 * Only what the template adds. Every field the country put on the receipt
 * stays, in its order; the receipt stays black on white, whatever colour the
 * template has, because a thermal head prints a dot or none. Where the
 * template prints English alone - which only a country allowing it offers -
 * the Arabic captions and lines are left out; the values stay as entered.
 */
final class TemplatedReceipt
{
    public static function apply(Receipt $receipt, InvoiceTemplate $template): Receipt
    {
        $text = fn (PrintLabel $label): array => array_column($template->lines($label), 'text');
        $english = $template->language === 'english';
        $label = fn (?PrintLabel $label): ?PrintLabel => $label === null || ! $english ? $label : new PrintLabel($label->en, '');
        $row = fn (ReceiptRow $row): ReceiptRow => new ReceiptRow($label($row->label), $row->value, $row->strong);
        $lines = fn (array $lines): array => $english ? array_values(array_filter($lines, fn (string $line): bool => preg_match('/\p{Arabic}/u', $line) !== 1)) : $lines;

        $terms = $text($template->terms);
        $bank = array_map(fn (array $row): string => $template->caption($row['label']).': '.$row['value'], $template->bankRows());

        return new Receipt(
            title: $label($receipt->title),
            sections: array_map(fn (ReceiptSection $section): ReceiptSection => new ReceiptSection(
                heading: $label($section->heading),
                name: $section->name,
                rows: array_map($row, $section->rows),
                lines: $section->lines,
            ), $receipt->sections),
            quantityLabel: $label($receipt->quantityLabel),
            unitPriceLabel: $label($receipt->unitPriceLabel),
            totalLabel: $label($receipt->totalLabel),
            items: array_map(fn (ReceiptItem $item): ReceiptItem => new ReceiptItem(
                description: $item->description,
                // The unit beside it in English alone: "2 day", not "2 day / يوم".
                quantity: $english ? trim((string) preg_replace('/\s*\/\s*\p{Arabic}[\p{Arabic}\s]*/u', '', $item->quantity)) : $item->quantity,
                unitPrice: $item->unitPrice,
                total: $item->total,
                discount: $item->discount,
                details: array_map($row, $item->details),
                notes: $item->notes,
            ), $receipt->items),
            itemsNote: $label($receipt->itemsNote),
            totals: array_map($row, $receipt->totals),
            notes: $receipt->notes === null ? null : $row($receipt->notes),
            qrPayload: $receipt->qrPayload,
            footer: $lines($receipt->footer),
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
