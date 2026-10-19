<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Contracts\Presentation;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Templates\InvoiceTemplate;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Invoice\Templates\TemplatedReceipt;
use App\Domains\Invoice\Templates\TemplateLayout;
use App\Domains\Organization\Models\Organization;
use DomainException;

/**
 * What the browser's print view of an invoice is given, for one paper.
 *
 * A sheet shows the country's page view in the organization's template
 * layout; a roll - or a sheet in the thermal layout - the country's
 * receipt, drawn by print/receipt.blade.php. Both under the same gate as the
 * PDF: a printed copy is a copy handed to the buyer.
 */
class InvoicePrintView
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly PrintAssets $assets,
        private readonly InvoiceTemplates $templates,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws DomainException when the invoice may not be given out yet
     */
    public function data(Invoice $invoice, Organization $organization, PaperSize $paper): array
    {
        // forRules: a switched-off country stops new issuing, not copies of
        // what was issued.
        $country = $this->jurisdictions->forRules($organization)->presentation();
        $country->assertShareable($invoice);

        $printing = $this->templates->printing($invoice, $organization, $country);

        return $this->view($country, $invoice, $organization, $paper, $printing, $printing['template']->businessQr
            ? $this->assets->extraQr($invoice, $organization, $paper, 'svg')
            : null);
    }

    /**
     * A made-up invoice laid out with a template not yet saved, for its
     * preview: never issued, so not gated; no business QR of the
     * organization's, which might be a link to a real invoice, but a
     * placeholder where the template shows one.
     *
     * @return array<string, mixed>
     */
    public function sample(Presentation $country, Invoice $invoice, Organization $organization, PaperSize $paper, InvoiceTemplate $template): array
    {
        $extraQr = $template->businessQr
            ? ['label' => __('Your business QR code'), 'image' => $this->assets->qrSvg(__('Sample'))]
            : null;

        return $this->view($country, $invoice, $organization, $paper, $this->templates->printing($invoice, $organization, $country, $template), $extraQr);
    }

    /**
     * @param  array{template: InvoiceTemplate, columns: array<string, bool>, itemCodes: array<string, string>}  $printing
     * @param  array{label: string, image: string}|null  $extraQr
     * @return array<string, mixed>
     */
    private function view(Presentation $country, Invoice $invoice, Organization $organization, PaperSize $paper, array $printing, ?array $extraQr): array
    {
        $template = $printing['template'];
        // A roll holds only the receipt's column; a sheet holds it in the
        // thermal layout.
        $thermal = $paper->isRoll() || $template->layout === TemplateLayout::Thermal;

        return [
            'invoice' => $invoice,
            'organization' => $organization,
            'paper' => $paper,
            'thermal' => $thermal,
            'layout' => $thermal ? 'print.receipt' : $country->pageView(false),
            'qr' => $this->assets->qrSvg((string) $invoice->qr_code),
            'receipt' => $thermal ? TemplatedReceipt::apply($country->receipt($invoice, $organization), $template) : null,
            'logo' => $thermal ? $this->assets->logo((string) $organization->id, monochrome: true) : null,
            'extraQr' => $extraQr,
            ...$printing,
        ];
    }

    /**
     * The invoice's QR code as an image file, 'png' or 'svg': '' when it has
     * none. Under the same gate as a printed copy.
     *
     * @throws DomainException when the invoice may not be given out yet
     */
    public function qrFile(Invoice $invoice, Organization $organization, string $format): string
    {
        $this->jurisdictions->forRules($organization)->presentation()->assertShareable($invoice);

        return $this->assets->qrFile((string) $invoice->qr_code, $format);
    }
}
