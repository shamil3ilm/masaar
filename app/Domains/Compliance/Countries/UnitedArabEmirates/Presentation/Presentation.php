<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\UaeDocuments;
use App\Domains\Compliance\Engine\Contracts\Presentation as PresentationPort;
use App\Domains\Compliance\Engine\DTOs\InvoicePage;
use App\Domains\Compliance\Engine\DTOs\PrintSample;
use App\Domains\Compliance\Engine\DTOs\Receipt;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentHistory;
use App\Domains\Compliance\Networks\Peppol\Sending\ProviderOutage;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DomainException;

/**
 * The UAE e-invoicing portal: a UAE invoice's page, where its e-invoice
 * stands, its received documents in the sidebar, and the human-readable
 * tax invoice a buyer not yet on electronic invoicing is given.
 *
 * The e-invoice is the tax invoice, and it reaches the buyer through the
 * service provider. A buyer that has not yet implemented electronic
 * invoicing is also given a regular tax invoice, "e.g. in pdf" (Electronic
 * Invoicing Guidelines v1.1, 10.2.2, p23); any buyer may ask for one
 * (6.2, p15). PrintedInvoice says which a copy is and draws it from the
 * e-invoice's own document. It is plain PDF/A, with no XML inside and no
 * seal: the guidelines and the mandatory-fields list ask for neither on a
 * PDF, and the e-invoice itself is the XML, delivered and reported apart.
 */
class Presentation implements PresentationPort
{
    private const VIEWS = 'countries.united-arab-emirates.';

    public function __construct(
        private readonly UaeDocuments $documents,
        private readonly DocumentHistory $history,
        private readonly ProviderOutage $outage,
        private readonly PrintedInvoice $printed,
        private readonly ReceiptContent $receipts,
        private readonly SampleInvoices $samples,
    ) {}

    public function tagline(): string
    {
        return __('UAE E-invoicing Portal');
    }

    /**
     * The received e-invoices, after Invoices: nobody else has any; and the
     * Peppol network's panel in the console, which the UAE sends through.
     */
    public function navigation(string $section): ?string
    {
        return match ($section) {
            self::NAV_MAIN => self::VIEWS.'navigation',
            self::NAV_CONSOLE => self::VIEWS.'console-navigation',
            default => null,
        };
    }

    /** Nothing to say on every page: the provider's outages show where they matter. */
    public function notice(): ?string
    {
        return null;
    }

    public function invoicePage(Invoice $invoice): InvoicePage
    {
        $endpoint = $invoice->jurisdiction_data['buyer_endpoint'] ?? null;
        $shareable = $this->printable($invoice);

        return new InvoicePage(
            heading: self::VIEWS.'invoice.heading',
            issueConfirm: __('It takes its number and goes to the buyer as a UAE e-invoice. This cannot be undone.'),
            actions: self::VIEWS.'invoice.actions',
            panel: self::VIEWS.'invoice.panel',
            buyerDetails: array_values(array_filter([
                $invoice->buyer_vat_number ? __('TRN :number', ['number' => $invoice->buyer_vat_number]) : null,
                $endpoint ? __('Peppol endpoint :endpoint', ['endpoint' => $endpoint]) : null,
            ])),
            exchangeNote: $invoice->currency !== 'AED' && $invoice->exchange_rate
                ? __('VAT reported in AED at :rate', ['rate' => $invoice->exchange_rate])
                : null,
            shareable: $shareable,
            data: [
                'document' => $document = $this->documents->outboundFor((string) $invoice->org_id, (string) $invoice->id),
                // Its transmission record, kept as it came (UAE Electronic
                // Invoicing Guidelines v1.1, p12).
                'history' => $document === null ? collect() : $this->history->of((string) $document->id),
                // The provider not answering: a document waiting says why (p45-46).
                'outage' => $this->outage->current(),
                'shareable' => $shareable,
                // Whether the buyer must be given the printed tax invoice.
                'printRequired' => $shareable && $this->printed->status($invoice) === PrintedInvoice::REQUIRED,
            ],
        );
    }

    /**
     * Once issued, and not where the e-invoice was rejected: a rejected
     * document is not a tax invoice to hand over.
     */
    public function assertShareable(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Draft || $invoice->invoice_number === null) {
            throw new DomainException(__('Issue the invoice first. A draft is not a tax invoice.'));
        }

        if ($invoice->status === InvoiceStatus::Rejected) {
            throw new DomainException(__('The e-invoice was rejected, so it is not a valid tax invoice. Correct it and issue a new one.'));
        }
    }

    /** No seal: neither the guidelines nor the mandatory-fields list ask for one on a PDF. */
    public function sealPdf(string $pdf, Invoice $invoice, Organization $organization): string
    {
        return $pdf;
    }

    /** One template for every sheet; the renderer sets the page. */
    public function pageView(bool $pdf): string
    {
        return self::VIEWS.($pdf ? 'print.pdf' : 'print.page');
    }

    public function receipt(Invoice $invoice, Organization $organization): Receipt
    {
        return $this->receipts->build($invoice);
    }

    /** What a UAE tax invoice shows on paper (PrintedFields), each with its rule. */
    public function printedFields(): array
    {
        return PrintedFields::all();
    }

    /** A tax invoice to a buyer not yet on electronic invoicing, made up (SampleInvoices). */
    public function sampleInvoice(?Organization $seller = null): ?PrintSample
    {
        return $this->samples->build($seller);
    }

    /**
     * None of the country-only customizations: the language a printed
     * invoice is in is the template's, English unless the organization
     * chooses both (LanguageRules::printLanguages).
     */
    public function usesCustomization(string $key): bool
    {
        return false;
    }

    /** Dates in the UAE are read in the Gregorian calendar alone. */
    public function alternateCalendar(): ?string
    {
        return null;
    }

    public function sendsWebhookEvent(string $event): bool
    {
        return true;
    }

    private function printable(Invoice $invoice): bool
    {
        try {
            $this->assertShareable($invoice);

            return true;
        } catch (DomainException) {
            return false;
        }
    }
}
