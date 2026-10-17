<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;

/**
 * Amounts a printed invoice shows that invoices do not store yet: a line's
 * own discount, and charges on the whole document (delivery, a service
 * charge), each with its VAT rate.
 *
 * The hook for the line-discount and document-charge work, which adds them.
 * Every layout - the full pages, the receipt, the printer image - reads them
 * here, so when the fields arrive under the names below they print
 * everywhere at once, and until then nothing is printed. If they arrive
 * under other names, these constants are the one place to change.
 */
final class PrintedAmounts
{
    /** The line's discount amount, on invoice_lines. */
    public const LINE_DISCOUNT = 'discount_amount';

    /**
     * The invoice's document-level charges: a list of reason, amount and
     * tax_rate, on invoices (a JSON column or a relation).
     */
    public const DOCUMENT_CHARGES = 'charges';

    /** The line's discount, or null when it has none. */
    public static function lineDiscount(InvoiceLine $line): ?string
    {
        $discount = $line->getAttribute(self::LINE_DISCOUNT);

        return is_numeric($discount) && (float) $discount > 0 ? (string) $discount : null;
    }

    /**
     * Whether any line has a discount: the discount column is shown only
     * then.
     *
     * @param  iterable<InvoiceLine>  $lines
     */
    public static function anyLineDiscount(iterable $lines): bool
    {
        foreach ($lines as $line) {
            if (self::lineDiscount($line) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The document's charges, printed above the VAT total.
     *
     * @return list<array{reason: string, amount: string, rate: string}>
     */
    public static function documentCharges(Invoice $invoice): array
    {
        $charges = $invoice->getAttribute(self::DOCUMENT_CHARGES);
        $printed = [];

        foreach (is_iterable($charges) ? $charges : [] as $charge) {
            $amount = data_get($charge, 'amount');

            if (is_numeric($amount)) {
                $printed[] = [
                    'reason' => (string) data_get($charge, 'reason', ''),
                    'amount' => (string) $amount,
                    'rate' => rtrim(rtrim((string) data_get($charge, 'tax_rate', ''), '0'), '.'),
                ];
            }
        }

        return $printed;
    }
}
