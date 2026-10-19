<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\UaeDocuments;
use App\Domains\Compliance\Engine\Contracts\Presentation as PresentationPort;
use App\Domains\Compliance\Engine\DTOs\InvoicePage;
use App\Domains\Compliance\Engine\DTOs\Receipt;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DomainException;

/**
 * The UAE e-invoicing portal: a UAE invoice's page, where its e-invoice
 * stands, and its received documents in the sidebar.
 *
 * The e-invoice is the document the buyer receives, through the service
 * provider, so there is no PDF, print or email copy to hand over: the module
 * says PdfAndEmail is not part of the country (Module::supportsFeature), and
 * the page offers the e-invoice's XML instead.
 */
class Presentation implements PresentationPort
{
    private const VIEWS = 'countries.united-arab-emirates.';

    public function __construct(
        private readonly UaeDocuments $documents,
    ) {}

    public function tagline(): string
    {
        return __('UAE E-invoicing Portal');
    }

    /** The received e-invoices, after Invoices: nobody else has any. */
    public function navigation(string $section): ?string
    {
        return $section === self::NAV_MAIN ? self::VIEWS.'navigation' : null;
    }

    public function invoicePage(Invoice $invoice): InvoicePage
    {
        $endpoint = $invoice->jurisdiction_data['buyer_endpoint'] ?? null;

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
            shareable: false,
            data: ['document' => $this->documents->outboundFor((string) $invoice->org_id, (string) $invoice->id)],
        );
    }

    /**
     * Never: the buyer receives the e-invoice itself. The answer is the one a
     * UAE invoice was given before the country had a port of its own - it
     * has no QR code, so it is not a document to hand over.
     */
    public function assertShareable(Invoice $invoice): void
    {
        throw new DomainException(__('Issue the invoice first. A draft has no QR code and is not a tax invoice.'));
    }

    /** Nothing to seal: assertShareable refuses first. */
    public function sealPdf(string $pdf, Invoice $invoice, Organization $organization): string
    {
        return $pdf;
    }

    /**
     * No printed copy to lay out: assertShareable refuses first.
     *
     * @throws DomainException as assertShareable does
     */
    public function pageView(bool $pdf): string
    {
        $this->refuse();
    }

    /**
     * @throws DomainException as assertShareable does
     */
    public function receipt(Invoice $invoice, Organization $organization): Receipt
    {
        $this->refuse();
    }

    /** A UAE invoice prints in one language: no order to choose. */
    public function usesCustomization(string $key): bool
    {
        return false;
    }

    private function refuse(): never
    {
        throw new DomainException(__('Issue the invoice first. A draft has no QR code and is not a tax invoice.'));
    }
}
