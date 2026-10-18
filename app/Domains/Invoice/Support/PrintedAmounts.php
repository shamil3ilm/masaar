<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceCharge;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Models\InvoicePrepayment;

/**
 * The amounts a printed invoice shows beyond its lines and totals: a line's
 * own discount and charges, the charges on the whole document with their
 * VAT, the taxable amount they make, and the prepayments a final invoice
 * deducts.
 *
 * Read from what the invoice stores - invoice_lines.discount_amount, the
 * invoice_charges rows and invoice_prepayments - so every layout (the full
 * pages, the PDF, the receipt and the printer image) prints the same figures
 * the signed document states.
 *
 * Amounts are as the invoice was priced: on an invoice priced with VAT in, a
 * line's discount and charges carry VAT as its unit price does, and a
 * document charge's VAT is the part of its amount that is VAT.
 */
final class PrintedAmounts
{
    /** The line's discount as entered, or null when it has none. */
    public static function lineDiscount(InvoiceLine $line): ?string
    {
        $discount = $line->discount_amount;

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
     * The line's own charges (BG-28), each with its amount as entered, or
     * its percentage's amount. They are in the line's total already.
     *
     * @return list<array{reason: string, amount: string, percent: ?string}>
     */
    public static function lineCharges(InvoiceLine $line): array
    {
        return $line->charges
            ->map(fn (InvoiceCharge $charge): array => [
                'reason' => (string) $charge->reason,
                'amount' => (string) $charge->amount,
                'percent' => $charge->percent !== null ? self::trimmed((string) $charge->percent) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The document's charges (BG-21), each its own total row: its net, its
     * rate and the VAT on it.
     *
     * @return list<array{reason: string, net: string, rate: string, vat: string}>
     */
    public static function documentCharges(Invoice $invoice): array
    {
        return $invoice->charges
            ->map(function (InvoiceCharge $charge) use ($invoice): array {
                $net = (string) $charge->net_amount;
                $rate = (string) ($charge->tax_rate ?? '0');

                return [
                    'reason' => (string) $charge->reason,
                    'net' => $net,
                    'rate' => self::trimmed($rate),
                    'vat' => $invoice->prices_include_vat
                        ? bcsub((string) $charge->amount, $net, 2)
                        : self::round(bcdiv(bcmul($net, $rate, 6), '100', 6)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The taxable amount (BT-109): the lines' net less the document
     * discount, plus the document charges.
     */
    public static function taxableAmount(Invoice $invoice): string
    {
        $charges = $invoice->charges->reduce(fn (string $sum, InvoiceCharge $charge): string => bcadd($sum, (string) $charge->net_amount, 2), '0.00');

        return bcadd(bcsub((string) $invoice->subtotal, (string) $invoice->discount_amount, 2), $charges, 2);
    }

    /**
     * The prepayment invoices a final invoice deducts, each with what it
     * paid including VAT. Empty for any other invoice.
     *
     * @return list<array{number: string, amount: string}>
     */
    public static function prepayments(Invoice $invoice): array
    {
        if ($invoice->prepaid_amount === null) {
            return [];
        }

        return $invoice->prepayments
            ->groupBy('invoice_number')
            ->map(fn ($rows, $number): array => [
                'number' => (string) $number,
                'amount' => $rows->reduce(fn (string $sum, InvoicePrepayment $row): string => bcadd($sum, bcadd((string) $row->taxable_amount, (string) $row->tax_amount, 2), 2), '0.00'),
            ])
            ->values()
            ->all();
    }

    /** A rate or percentage without its trailing zeros: 15.00 is 15. */
    private static function trimmed(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    /** Half away from zero, as InvoiceTotals rounds VAT. */
    private static function round(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', 2);
    }
}
