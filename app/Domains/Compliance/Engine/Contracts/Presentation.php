<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\InvoicePage;
use App\Domains\Compliance\Engine\DTOs\PrintedField;
use App\Domains\Compliance\Engine\DTOs\PrintSample;
use App\Domains\Compliance\Engine\DTOs\Receipt;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Exception;

/**
 * How the portal shows a country: what it calls itself, the links the
 * country adds, an invoice's page, and when and how an invoice is handed to
 * the buyer - on a full page or a till's receipt.
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

    /**
     * The admin console's links to the country's own panels, which the
     * console groups under the country's name.
     */
    public const NAV_CONSOLE = 'console';

    /** What the portal calls itself, under the logo. */
    public function tagline(): string;

    /**
     * The Blade view of the links the country adds to a section of the
     * sidebar (NAV_MAIN, NAV_ADMIN or the console's NAV_CONSOLE); null when
     * it adds none there.
     */
    public function navigation(string $section): ?string;

    /**
     * The Blade view of a notice every page of the portal and the console
     * shows about how this deployment files in the country - that it files
     * with a test environment, say - or null when there is none to show.
     * Given no variables; it decides for itself whether to say anything.
     */
    public function notice(): ?string;

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
     * Whether the issued document and its QR are still withheld: issued, but
     * not yet the document the buyer may be given - where the country's
     * authority stamps it first. An integration reads neither until then.
     */
    public function withheld(Invoice $invoice): bool;

    /**
     * The rendered PDF as the country requires it to be handed over: sealed
     * with the seller's own credentials where the country asks for that, as
     * it is where it does not, or where there is nothing to seal with yet.
     *
     * @throws Exception when the seller's stored credentials cannot seal
     */
    public function sealPdf(string $pdf, Invoice $invoice, Organization $organization): string;

    /**
     * The Blade view of an invoice on a full sheet - A4, Letter or A5 - for
     * the PDF renderer ($pdf) or for the browser's print view. Given the
     * invoice, the organization and $paper; the PDF's view also $simplified,
     * $qr and $logo as data URIs, the browser's $qr as SVG markup.
     *
     * Only asked for an invoice assertShareable() allowed.
     */
    public function pageView(bool $pdf): string;

    /**
     * The invoice as a till prints it on a roll: every field the country
     * requires on a printed copy, in one column. The browser's print view,
     * the PDF and the printer image all draw this.
     *
     * Only asked for an invoice assertShareable() allowed.
     */
    public function receipt(Invoice $invoice, Organization $organization): Receipt;

    /**
     * Every field the country requires on a printed copy of an invoice, with
     * the rule that requires it. The country's page view and receipt print
     * each of them in every layout and on every paper; an invoice template
     * restyles and moves them but cannot hide them, and its screens show
     * them as locked. A country that hands over no printed copy still
     * declares what one would carry.
     *
     * @return list<PrintedField>
     */
    public function printedFields(): array;

    /**
     * A made-up invoice in the country's form, for an invoice template's
     * preview: from the seller given, or from a made-up one for the
     * platform's default. Never stored or issued, and its QR code says it
     * is a sample. Null where the country hands over no printed copy.
     */
    public function sampleInvoice(?Organization $seller = null): ?PrintSample;

    /**
     * Whether the country uses a customization only some countries do
     * (Definition $country) - which language a bilingual invoice prints
     * first, say. One it does not use is not shown to its organizations.
     */
    public function usesCustomization(string $key): bool;

    /**
     * The calendar, besides the Gregorian, a date is read against in the
     * country - a Unicode calendar identifier, as Intl takes it - or null
     * where there is none. The date picker shows each day in it; every
     * value stays Gregorian.
     */
    public function alternateCalendar(): ?string;

    /**
     * Whether the country sends a webhook event (WebhookService::EVENTS):
     * one it never sends is not offered to its organizations.
     */
    public function sendsWebhookEvent(string $event): bool;
}
