<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation;

/**
 * Why a match was suggested, in words: the codes the matcher keeps on it
 * (reconciliation_matches.basis).
 */
final class MatchBasis
{
    public static function label(string $code): string
    {
        return match ($code) {
            'gateway_id' => __('The gateway\'s transaction id'),
            'payment_reference' => __('A recorded payment\'s reference'),
            'refund_reference' => __('A recorded refund\'s reference'),
            'invoice_number' => __('The invoice number is in the line'),
            'invoice_numbers' => __('The invoice numbers are in the line'),
            'amount_exact' => __('The exact amount'),
            'amount_within_tolerance' => __('The amount, within the tolerance'),
            'amount_differs' => __('The amount differs'),
            'date_window' => __('Within the date window'),
            'payer_name' => __('The line names the customer'),
            'amount_sum' => __('Invoices that add up to it'),
            'lines_sum' => __('Lines that add up to it'),
            'short' => __('Short of what is owed'),
            'over' => __('More than is owed'),
            'manual' => __('Matched by hand'),
            default => $code,
        };
    }
}
