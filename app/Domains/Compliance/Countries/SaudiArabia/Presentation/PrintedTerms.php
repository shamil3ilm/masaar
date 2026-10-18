<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Engine\DTOs\PrintLabel;
use App\Domains\Compliance\Engine\DTOs\ReceiptRow;
use App\Domains\Invoice\Models\Invoice;

/**
 * What a printed Saudi invoice says about the sale beyond its lines: the
 * buyer's purchase order (BT-13), the contract (BT-12) and the buyer's own
 * reference (BT-10), and when and how it is to be paid - the due date (BT-9)
 * and the payment terms (KSA-22). Each only when the invoice has it.
 *
 * One list for every layout - the full pages, the PDF and the receipt - so a
 * reference the signed document carries is never missing from one copy.
 */
final class PrintedTerms
{
    /**
     * @return list<ReceiptRow>
     */
    public static function of(Invoice $invoice): array
    {
        $terms = [
            [new PrintLabel('Purchase order', 'رقم أمر الشراء'), $invoice->order_reference],
            [new PrintLabel('Contract', 'رقم العقد'), $invoice->contract_reference],
            [new PrintLabel('Buyer reference', 'مرجع المشتري'), $invoice->buyer_reference],
            [new PrintLabel('Due date', 'تاريخ الاستحقاق'), $invoice->due_date?->toDateString()],
            [new PrintLabel('Payment terms', 'شروط الدفع'), $invoice->payment_terms],
        ];

        return array_values(array_map(
            fn (array $term): ReceiptRow => new ReceiptRow($term[0], (string) $term[1]),
            array_filter($terms, fn (array $term): bool => filled($term[1])),
        ));
    }
}
