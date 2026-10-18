<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Branding\Services\ThemeService;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\MonochromeImage;
use App\Support\QrCodeRenderer;
use GdImage;

/**
 * The pictures on a printed invoice - the seller's logo and the QR code - in
 * the forms the PDF, the print view and the receipt image each take.
 */
class PrintAssets
{
    public function __construct(
        private readonly QrCodeRenderer $qr,
        private readonly ThemeService $themes,
        private readonly MonochromeImage $monochrome,
        private readonly InvoiceExtraQr $extraQr,
    ) {}

    /** The QR code as a PNG data URI; null for no payload. */
    public function qrPng(string $payload): ?string
    {
        $png = $this->qr->png($payload);

        return $png === '' ? null : 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * The organization's extra QR for this paper (InvoiceExtraQr), as a PNG
     * data URI ('png') or SVG markup ('svg'), with its label; null for none.
     *
     * @return array{label: string, image: string}|null
     */
    public function extraQr(Invoice $invoice, Organization $organization, PaperSize $paper, string $format): ?array
    {
        $extra = $this->extraQr->for($invoice, $organization, $paper);

        if ($extra === null || $extra['payload'] === '') {
            return null;
        }

        $image = $format === 'svg'
            ? $this->qr->svg($extra['payload'], $extra['encoding'])
            : 'data:image/png;base64,'.base64_encode($this->qr->png($extra['payload'], $extra['encoding']));

        return ['label' => $extra['label'], 'image' => $image];
    }

    /** The QR code as a file to download: 'png' or 'svg'; '' for no payload. */
    public function qrFile(string $payload, string $format): string
    {
        return $format === 'svg' ? $this->qr->svg($payload) : $this->qr->png($payload);
    }

    /** The QR code as SVG markup, for the browser; '' for no payload. */
    public function qrSvg(string $payload): string
    {
        return $this->qr->svg($payload);
    }

    /**
     * The organization's own logo as a data URI, as uploaded; or, for a
     * receipt roll, in black and white (MonochromeImage), which a logo GD
     * cannot read - an SVG - does not survive.
     */
    public function logo(string $organizationId, bool $monochrome = false): ?string
    {
        $logo = $this->themes->organizationLogo($organizationId);
        $contents = $logo === null ? false : @file_get_contents($logo['path']);

        if ($contents === false) {
            return null;
        }

        return $monochrome
            ? $this->monochrome->dataUri($contents, 600)
            : 'data:'.$logo['type'].';base64,'.base64_encode($contents);
    }

    /** The logo in black and white, no wider than $width dots. */
    public function logoImage(string $organizationId, int $width): ?GdImage
    {
        $logo = $this->themes->organizationLogo($organizationId);
        $contents = $logo === null ? false : @file_get_contents($logo['path']);

        return $contents === false ? null : $this->monochrome->image($contents, $width);
    }
}
