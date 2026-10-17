<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\SigningException;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificatePem;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\PdfStamp;
use App\Domains\Compliance\Engine\Contracts\Presentation as PresentationPort;
use App\Domains\Compliance\Engine\DTOs\InvoicePage;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use BackedEnum;
use DomainException;

/**
 * The ZATCA portal: a Saudi invoice's page, with its counter, hash and QR,
 * and the copies of it a buyer is given.
 *
 * A Saudi invoice goes to the buyer only once issued, and a standard one only
 * once ZATCA has cleared it. The PDF a buyer is given carries the device's
 * PAdES stamp, made with the credentials its XML was signed with.
 */
class Presentation implements PresentationPort
{
    private const VIEWS = 'countries.saudi-arabia.';

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly PdfStamp $stamp,
    ) {}

    public function tagline(): string
    {
        return __('ZATCA Portal');
    }

    /** ZATCA onboarding, among the administrator's links. */
    public function navigation(string $section): ?string
    {
        return $section === self::NAV_ADMIN ? self::VIEWS.'navigation' : null;
    }

    /**
     * A simplified invoice is shareable once issued, a standard one once
     * cleared: the PDF, the email form and the print button follow that, and
     * InvoicePdf and the print view refuse the rest (assertShareable).
     */
    public function invoicePage(Invoice $invoice): InvoicePage
    {
        // ZATCA's annex titles the two documents exactly so.
        $simplified = ($invoice->type instanceof BackedEnum ? $invoice->type->value : (string) $invoice->type) === 'simplified';
        $shareable = $invoice->hash !== null && ($simplified || $invoice->cleared_xml !== null);

        return new InvoicePage(
            heading: self::VIEWS.'invoice.heading',
            issueConfirm: __('Issue this invoice? It gets a counter and a hash, joins the chain, and cannot be issued again.'),
            actions: self::VIEWS.'invoice.actions',
            panel: self::VIEWS.'invoice.panel',
            buyerDetails: $invoice->buyer_vat_number ? [__('VAT :number', ['number' => $invoice->buyer_vat_number])] : [],
            exchangeNote: $invoice->currency !== FatooraConfig::CURRENCY_SAR && $invoice->exchange_rate
                ? __('VAT reported in SAR at :rate', ['rate' => $invoice->exchange_rate])
                : null,
            shareable: $shareable,
            data: ['simplified' => $simplified, 'shareable' => $shareable],
        );
    }

    /**
     * Only an issued invoice; a standard one only once ZATCA has cleared it.
     */
    public function assertShareable(Invoice $invoice): void
    {
        if ($invoice->hash === null || $invoice->qr_code === null || $invoice->status === InvoiceStatus::Draft) {
            throw new DomainException(__('Issue the invoice first. A draft has no QR code and is not a tax invoice.'));
        }

        if ($invoice->status === InvoiceStatus::Rejected) {
            throw new DomainException(__('ZATCA rejected this invoice, so it is not a valid tax invoice. Correct it and issue a new one.'));
        }

        if ($invoice->type === InvoiceType::Standard && $invoice->cleared_xml === null) {
            throw new DomainException(__('ZATCA has not cleared this invoice yet. A standard invoice may not be given to the buyer until it is.'));
        }
    }

    /**
     * The PDF with the device's PAdES stamp, which ZATCA requires on a PDF/A-3
     * invoice; as it is when the device has no certificate to stamp with.
     *
     * The same credentials the XML was signed with, resolved the same way: the
     * branch's own, else the organization's. With none it is Phase 1, where
     * nothing is signed, and that is not a reason to withhold the invoice.
     *
     * @throws SigningException when the stored key cannot sign
     */
    public function sealPdf(string $pdf, Invoice $invoice, Organization $organization): string
    {
        $signing = $this->credentials->signing((string) $organization->id, $invoice->branch_id);
        $privateKey = $signing['privateKey'] ?? null;
        $certificate = CertificatePem::from($signing['pcsid'] ?? null);

        if (empty($privateKey) || $certificate === null) {
            return $pdf;
        }

        return $this->stamp->sign($pdf, (string) $privateKey, $certificate);
    }
}
