<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Invoice\Models\Invoice;

/**
 * The language a country's invoices have to be written in, beyond the
 * captions the country's own templates print.
 *
 * A country whose invoices must carry Arabic text a person reads - the
 * buyer's name, each line, the notes - refuses a draft without it through
 * its own InvoiceRules and SellerRules. Shared screens ask this port only
 * what they have to show: whether the Arabic names they keep (an
 * organization's, a customer's, a product's) are required, and what a
 * saved invoice still lacks, so a draft let through under a grace period
 * (LanguageGrace) is flagged wherever it is shown.
 */
interface LanguageRules
{
    /** A saved customer's Arabic name, for arabicNameRules(). */
    public const CUSTOMER = 'customer';

    /** A saved product's Arabic name, for arabicNameRules(). */
    public const PRODUCT = 'product';

    /**
     * Whether the country's invoices must carry Arabic text: the Arabic
     * names of the seller, its customers and its products are then required
     * rather than optional, and its invoices print in both languages.
     */
    public function requiresArabic(): bool;

    /**
     * What the Arabic name of a saved customer or product (CUSTOMER,
     * PRODUCT) has to be, as validation rules: optional where the country
     * does not require Arabic.
     *
     * @return list<mixed>
     */
    public function arabicNameRules(string $of): array;

    /**
     * What the invoice lacks of the text the country requires, by field, in
     * words a person can act on. Empty when nothing is required or nothing
     * is missing.
     *
     * @return array<string, string>
     */
    public function missingText(Invoice $invoice): array;
}
