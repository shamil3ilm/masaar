<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

/**
 * The invoices a credit or debit note names in its billing reference
 * (BT-25): one, or several separated by commas or semicolons - ZATCA's
 * Detailed Guidelines (4.3) let one note correct several invoices. Each is
 * an invoice number, or a row id an integration sent instead.
 */
final class BillingReferences
{
    /**
     * @return list<string>
     */
    public static function of(?string $billingRef): array
    {
        $references = array_map('trim', preg_split('/[,;]/', (string) $billingRef) ?: []);

        return array_values(array_unique(array_filter($references, fn (string $reference): bool => $reference !== '')));
    }
}
