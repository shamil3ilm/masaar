<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\SigningException;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificatePem;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\QrCodeRenderer;
use DomainException;
use Mpdf\Mpdf;

/**
 * An issued invoice as a PDF the buyer can keep.
 *
 * PDF/A-3 with the invoice's XML inside it - the stamped copy for a cleared
 * standard invoice, the signed one for a simplified invoice. That is the form
 * Saudi buyers' systems expect: a page a person reads and the document a
 * machine reads, in one file. The XML is the invoice; the page is its picture.
 *
 * Built from its own template rather than the browser print view: the PDF
 * renderer does not run the stylesheet the screens use. Arabic is shaped by
 * the renderer, which plain browser-print-to-PDF tooling does not do.
 */
class InvoicePdf
{
    public function __construct(
        private readonly QrCodeRenderer $qr,
        private readonly ThemeService $themes,
        private readonly CredentialStore $credentials,
        private readonly PdfStamp $stamp,
    ) {}

    /**
     * @throws DomainException when the invoice may not be given out yet
     * @throws SigningException when the stored key cannot sign
     */
    public function render(Invoice $invoice, Organization $organization): string
    {
        $this->assertShareable($invoice);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 14,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $this->tempDir(),
        ]);

        $mpdf->PDFA = true;
        $mpdf->PDFAversion = '3-B';
        // Corrects what PDF/A forbids - transparency in the logo, say -
        // rather than refusing to write the file.
        $mpdf->PDFAauto = true;
        $mpdf->SetTitle((string) $invoice->invoice_number);
        $mpdf->SetAuthor($organization->documentName());
        $mpdf->SetCreator((string) config('app.name', 'Masaar'));

        $mpdf->WriteHTML(view('pdf.invoice', [
            'invoice' => $invoice,
            'organization' => $organization,
            'simplified' => $invoice->type === InvoiceType::Simplified,
            'qr' => $this->qrImage((string) $invoice->qr_code),
            'logo' => $this->logo((string) $organization->id),
        ])->render());

        $xml = $invoice->legal_xml;

        if (is_string($xml) && $xml !== '') {
            $mpdf->SetAssociatedFiles([[
                'name' => $this->fileName($invoice, 'xml'),
                'mime' => 'text/xml',
                'description' => 'The e-invoice as filed with ZATCA',
                'AFRelationship' => 'Alternative',
                'content' => $xml,
            ]]);
        }

        return $this->stamped($mpdf->Output('', 'S'), $invoice, $organization);
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
    private function stamped(string $pdf, Invoice $invoice, Organization $organization): string
    {
        $signing = $this->credentials->signing((string) $organization->id, $invoice->branch_id);
        $privateKey = $signing['privateKey'] ?? null;
        $certificate = CertificatePem::from($signing['pcsid'] ?? null);

        if (empty($privateKey) || $certificate === null) {
            return $pdf;
        }

        return $this->stamp->sign($pdf, (string) $privateKey, $certificate);
    }

    public function fileName(Invoice $invoice, string $extension = 'pdf'): string
    {
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($invoice->invoice_number ?? $invoice->id));

        return trim((string) $number, '-').'.'.$extension;
    }

    /**
     * Only an issued invoice; a standard one only once ZATCA has cleared it.
     *
     * @throws DomainException
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

    private function qrImage(string $payload): ?string
    {
        $png = $this->qr->png($payload);

        return $png === '' ? null : 'data:image/png;base64,'.base64_encode($png);
    }

    private function logo(string $organizationId): ?string
    {
        $logo = $this->themes->organizationLogo($organizationId);

        if ($logo === null) {
            return null;
        }

        $contents = @file_get_contents($logo['path']);

        return $contents === false ? null : 'data:'.$logo['type'].';base64,'.base64_encode($contents);
    }

    private function tempDir(): string
    {
        // mPDF keeps its font cache here. Configurable so parallel test
        // processes can each have their own: on a cold cache two of them
        // build the same font file, and Windows refuses to rename one over
        // the other while it is open.
        $dir = (string) config('invoices.pdf_cache_dir', storage_path('framework/cache/pdf'));

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
