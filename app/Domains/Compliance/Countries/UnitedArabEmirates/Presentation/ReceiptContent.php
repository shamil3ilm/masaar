<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Engine\DTOs\PrintLabel;
use App\Domains\Compliance\Engine\DTOs\Receipt;
use App\Domains\Compliance\Engine\DTOs\ReceiptItem;
use App\Domains\Compliance\Engine\DTOs\ReceiptRow;
use App\Domains\Compliance\Engine\DTOs\ReceiptSection;
use App\Domains\Invoice\Models\Invoice;

/**
 * A UAE invoice on a till's roll: the human-readable invoice
 * (PrintedInvoice) in one column, every field Art. 59 of the VAT Executive
 * Regulation asks of a tax invoice, and no QR code (Electronic Invoicing
 * Guidelines v1.1, 5.3). Each line states its net, its rate, and its VAT and
 * amount payable in dirhams (12.2 item 16).
 */
final class ReceiptContent
{
    public function __construct(
        private readonly PrintedInvoice $printed,
    ) {}

    public function build(Invoice $invoice): Receipt
    {
        $page = $this->printed->page($invoice);

        return new Receipt(
            title: $page['title'],
            sections: [
                $this->party($page['seller']),
                new ReceiptSection(heading: null, name: null, rows: $page['meta']),
                $this->party($page['buyer']),
            ],
            quantityLabel: new PrintLabel('Qty', 'الكمية'),
            unitPriceLabel: new PrintLabel('Unit price', 'سعر الوحدة'),
            totalLabel: new PrintLabel('Net', 'الصافي'),
            items: array_map(fn (array $line): ReceiptItem => new ReceiptItem(
                description: $line['name'],
                quantity: $line['quantity'].' '.$line['unit'],
                unitPrice: $line['price'],
                total: $line['net'],
                discount: $line['discount'],
                details: [
                    new ReceiptRow(new PrintLabel('VAT '.$line['rate'], 'ضريبة القيمة المضافة'), $line['vat']),
                    new ReceiptRow(new PrintLabel('Amount payable', 'المبلغ المستحق'), $line['total']),
                ],
                notes: array_values(array_filter([$line['description'], $line['exemption']])),
            ), $page['lines']),
            itemsNote: null,
            // A credit note's adjustment after the totals (Art. 60(1)(e)).
            totals: [...$page['totals'], ...($page['credit'] ?? [])],
            notes: null,
            qrPayload: '',
            // In both languages: the template leaves out the one it does
            // not print (TemplatedReceipt).
            footer: [
                ...array_merge(...array_map(fn (PrintLabel $statement): array => [$statement->en, $statement->ar], $page['statements'])),
                ...array_map(fn (ReceiptRow $row): string => $row->label->en.': '.$row->value, $page['payment']),
                $page['note']->en,
                $page['note']->ar,
            ],
        );
    }

    /**
     * @param  array{heading: PrintLabel, name: string, rows: list<ReceiptRow>, address: string}  $party
     */
    private function party(array $party): ReceiptSection
    {
        return new ReceiptSection(
            heading: $party['heading'],
            name: $party['name'],
            rows: $party['rows'],
            lines: $party['address'] === '' ? [] : [$party['address']],
        );
    }
}
