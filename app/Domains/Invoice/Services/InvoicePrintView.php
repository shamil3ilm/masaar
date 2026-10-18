<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DomainException;

/**
 * What the browser's print view of an invoice is given, for one paper.
 *
 * A sheet shows the country's page view; a roll the country's receipt,
 * drawn by print/receipt.blade.php. Both under the same gate as the PDF: a
 * printed copy is a copy handed to the buyer.
 */
class InvoicePrintView
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly PrintAssets $assets,
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

        return [
            'invoice' => $invoice,
            'organization' => $organization,
            'paper' => $paper,
            'layout' => $paper->isRoll() ? 'print.receipt' : $country->pageView(false),
            'qr' => $this->assets->qrSvg((string) $invoice->qr_code),
            'receipt' => $paper->isRoll() ? $country->receipt($invoice, $organization) : null,
            'logo' => $paper->isRoll() ? $this->assets->logo((string) $organization->id, monochrome: true) : null,
            'extraQr' => $this->assets->extraQr($invoice, $organization, $paper, 'svg'),
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
