<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

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
     * What a free text the invoice prints and the organization keeps - a
     * customer's payment terms, a QR code's caption - has to be when given,
     * as validation rules.
     *
     * @return list<mixed>
     */
    public function arabicTextRules(): array;

    /**
     * What the invoice lacks of the text the country requires, by field, in
     * words a person can act on. Empty when nothing is required or nothing
     * is missing.
     *
     * @return array<string, string>
     */
    public function missingText(Invoice $invoice): array;

    /**
     * What a draft the platform builds itself, past the request rules - its
     * own invoice to a customer for a plan - lacks of that text, the
     * seller's own included. Empty when nothing is required or nothing is
     * missing.
     *
     * @param  array<string, mixed>  $draft  as InvoiceDrafter takes it
     * @return array<string, string>
     */
    public function missingInDraft(array $draft, Organization $seller): array;
}
