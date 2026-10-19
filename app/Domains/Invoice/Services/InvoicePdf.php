<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Contracts\Presentation;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Templates\InvoiceTemplate;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Invoice\Templates\TemplatedReceipt;
use App\Domains\Invoice\Templates\TemplateLayout;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Exception;
use Mpdf\Mpdf;

/**
 * An issued invoice as a PDF the buyer can keep.
 *
 * PDF/A-3 with the invoice's XML inside it - the stamped copy for a cleared
 * standard invoice, the signed one for a simplified invoice. That is the form
 * Saudi buyers' systems expect: a page a person reads and the document a
 * machine reads, in one file. The XML is the invoice; the page is its picture.
 *
 * A sheet is built from its own template rather than the browser print
 * view: the PDF renderer does not run the stylesheet the screens use. A roll
 * shares the print view's markup and its one plain stylesheet. Arabic is
 * shaped by the renderer, which plain browser-print-to-PDF tooling does not do.
 *
 * On any paper (PaperSize): a sheet is the country's page laid out at that
 * size; a roll is the country's receipt in one column, on a page as long as
 * the receipt. Every one is the same PDF/A-3 with the same XML and the same
 * seal, so a buyer's system reads a till's receipt as it reads an A4 page.
 *
 * When an invoice may be given out, what a printed copy carries, and the seal
 * the country puts on the file, are the organization's country's
 * (Presentation): this renders, and the country decides.
 */
class InvoicePdf
{
    /**
     * The longest a roll's page may be, in millimetres: PDF/A keeps a page
     * within 14,400 units (5,080 mm). A receipt longer than that continues
     * on a second page.
     */
    private const MAX_ROLL_LENGTH = 5000.0;

    /** A roll's margin above and below the receipt, in millimetres. */
    private const ROLL_END_MARGIN = 3.0;

    public function __construct(
        private readonly PrintAssets $assets,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly InvoiceTemplates $templates,
    ) {}

    /**
     * @throws DomainException when the invoice may not be given out yet
     * @throws Exception when the country's seal cannot be made with the stored credentials
     */
    public function render(Invoice $invoice, Organization $organization, PaperSize $paper = PaperSize::A4): string
    {
        $country = $this->country($organization);
        $country->assertShareable($invoice);

        $mpdf = $this->compose($this->html($invoice, $organization, $paper), $paper, (string) $invoice->invoice_number, $organization);

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

        return $country->sealPdf($mpdf->Output('', 'S'), $invoice, $organization);
    }

    /**
     * A made-up invoice as a PDF, laid out with a template not yet saved:
     * the template preview's download. The same PDF/A-3 page, but no XML
     * inside and no seal - it is not an invoice, and the seller's key signs
     * only what it issued.
     */
    public function sample(Presentation $country, Invoice $invoice, Organization $organization, PaperSize $paper, InvoiceTemplate $template): string
    {
        $printing = $this->templates->printing($invoice, $organization, $country, $template);
        $extraQr = $template->businessQr
            ? ['label' => __('Your business QR code'), 'image' => (string) $this->assets->qrPng(__('Sample'))]
            : null;

        return $this->compose($this->markup($country, $invoice, $organization, $paper, $printing, $extraQr), $paper, __('Sample invoice'), $organization)
            ->Output('', 'S');
    }

    /**
     * The PDF/A-3 document with the page drawn on it: a sheet by its size, a
     * roll as long as what is drawn.
     */
    private function compose(string $html, PaperSize $paper, string $title, Organization $organization): Mpdf
    {
        $mpdf = $this->document($paper, $paper->isRoll() ? $this->rollLength($html, $paper) : null);

        $mpdf->PDFA = true;
        $mpdf->PDFAversion = '3-B';
        // Corrects what PDF/A forbids - transparency in the logo, say -
        // rather than refusing to write the file.
        $mpdf->PDFAauto = true;
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor($organization->documentName());
        $mpdf->SetCreator((string) config('app.name', 'Masaar'));

        $mpdf->WriteHTML($html);

        return $mpdf;
    }

    public function fileName(Invoice $invoice, string $extension = 'pdf'): string
    {
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($invoice->invoice_number ?? $invoice->id));

        return trim((string) $number, '-').'.'.$extension;
    }

    /**
     * Whether the invoice may be given to the buyer yet, by its country's
     * rule: the PDF and the printed copy are both copies handed over.
     *
     * @throws DomainException saying why not
     */
    public function assertShareable(Invoice $invoice, Organization $organization): void
    {
        $this->country($organization)->assertShareable($invoice);
    }

    /**
     * The organization's country, whether or not it is switched on: a
     * switched-off country stops new issuing, not copies of what was issued.
     */
    private function country(Organization $organization): Presentation
    {
        return $this->jurisdictions->forRules($organization)->presentation();
    }

    /**
     * A blank document for the paper: a sheet by its name, a roll by its
     * width and the length it is given.
     */
    private function document(PaperSize $paper, ?float $length): Mpdf
    {
        $margin = $paper->marginMm();

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => $paper->isRoll() ? [$paper->widthMm(), $length ?? self::MAX_ROLL_LENGTH] : match ($paper) {
                PaperSize::Letter => 'Letter',
                PaperSize::A5 => 'A5',
                default => 'A4',
            },
            'margin_left' => $margin,
            'margin_right' => $margin,
            'margin_top' => $paper->isRoll() ? self::ROLL_END_MARGIN : $margin,
            'margin_bottom' => $paper->isRoll() ? self::ROLL_END_MARGIN : $margin,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $this->tempDir(),
        ]);
    }

    /**
     * The markup the renderer draws on the paper: the country's page for a
     * sheet in the organization's template layout, its receipt for a roll
     * or a sheet in the thermal layout.
     */
    public function html(Invoice $invoice, Organization $organization, PaperSize $paper = PaperSize::A4): string
    {
        $country = $this->country($organization);
        $printing = $this->templates->printing($invoice, $organization, $country);

        return $this->markup($country, $invoice, $organization, $paper, $printing, $printing['template']->businessQr
            ? $this->assets->extraQr($invoice, $organization, $paper, 'png')
            : null);
    }

    /**
     * @param  array{template: InvoiceTemplate, columns: array<string, bool>, itemCodes: array<string, string>}  $printing
     * @param  array{label: string, image: string}|null  $extraQr  the organization's own QR, as a PNG data URI
     */
    private function markup(Presentation $country, Invoice $invoice, Organization $organization, PaperSize $paper, array $printing, ?array $extraQr): string
    {
        $template = $printing['template'];

        if ($paper->isRoll() || $template->layout === TemplateLayout::Thermal) {
            return $this->receiptHtml($country, $invoice, $organization, $paper, $template, $extraQr);
        }

        return view($country->pageView(true), [
            'invoice' => $invoice,
            'organization' => $organization,
            'simplified' => $invoice->type === InvoiceType::Simplified,
            'qr' => $this->assets->qrPng((string) $invoice->qr_code),
            'logo' => $this->assets->logo((string) $organization->id),
            'paper' => $paper,
            'extraQr' => $extraQr,
            ...$printing,
        ])->render();
    }

    /**
     * The receipt, in the markup the print view uses and with the stylesheet
     * it uses, given to the renderer whole: on a roll, or in the middle of a
     * sheet in the thermal layout. Black on white whatever the template's
     * colour.
     *
     * @param  array{label: string, image: string}|null  $extra
     */
    private function receiptHtml(Presentation $country, Invoice $invoice, Organization $organization, PaperSize $paper, InvoiceTemplate $template, ?array $extra): string
    {
        $qr = $this->assets->qrPng((string) $invoice->qr_code);

        return view('pdf.receipt', [
            'extraQr' => $extra === null ? null : [
                'label' => $extra['label'],
                'image' => '<img src="'.$extra['image'].'" alt="" style="width: 24mm; height: 24mm;">',
            ],
            'receipt' => TemplatedReceipt::apply($country->receipt($invoice, $organization), $template),
            'paper' => $paper,
            // Sized here too: the renderer reads an image's size more surely
            // from the element than from a stylesheet.
            'qr' => $qr === null ? '' : '<img src="'.$qr.'" alt="" style="width: '.$paper->qrMm().'mm; height: '.$paper->qrMm().'mm;">',
            'logo' => $this->assets->logo((string) $organization->id, monochrome: true),
        ])->render();
    }

    /**
     * How long the roll's page must be: the receipt laid out once on the
     * longest page allowed, and measured.
     */
    private function rollLength(string $html, PaperSize $paper): float
    {
        $probe = $this->document($paper, self::MAX_ROLL_LENGTH);
        $probe->WriteHTML($html);

        if ($probe->page > 1) {
            return self::MAX_ROLL_LENGTH;
        }

        return min(self::MAX_ROLL_LENGTH, ceil($probe->y + self::ROLL_END_MARGIN + 1));
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
