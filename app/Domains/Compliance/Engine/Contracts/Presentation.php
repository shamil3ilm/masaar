<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\InvoicePage;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Exception;

/**
 * How the portal shows a country: what it calls itself, the links the
 * country adds, an invoice's page, and when and how an invoice is handed to
 * the buyer.
 *
 * Shared screens ask the organization's module for these
 * (JurisdictionResolver::forRules(), so a switched-off country's screens
 * still open) and never ask which country it is. What a person is told after
 * issuing is the same port's, through IssueNotices.
 */
interface Presentation
{
    /** The sidebar's links every member sees, after Invoices. */
    public const NAV_MAIN = 'main';

    /** The sidebar's links for the organization's administrators. */
    public const NAV_ADMIN = 'admin';

    /** What the portal calls itself, under the logo. */
    public function tagline(): string;

    /**
     * The Blade view of the links the country adds to a section of the
     * sidebar (NAV_MAIN or NAV_ADMIN); null when it adds none there.
     */
    public function navigation(string $section): ?string;

    /** What an invoice's page shows for the country. */
    public function invoicePage(Invoice $invoice): InvoicePage;

    /**
     * Whether the invoice may be given to the buyer now - downloaded,
     * printed or emailed - and if not, why.
     *
     * @throws DomainException saying why not, in words a person can act on
     */
    public function assertShareable(Invoice $invoice): void;

    /**
     * The rendered PDF as the country requires it to be handed over: sealed
     * with the seller's own credentials where the country asks for that, as
     * it is where it does not, or where there is nothing to seal with yet.
     *
     * @throws Exception when the seller's stored credentials cannot seal
     */
    public function sealPdf(string $pdf, Invoice $invoice, Organization $organization): string;
}
