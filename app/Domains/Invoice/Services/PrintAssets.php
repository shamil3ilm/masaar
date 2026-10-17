<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Branding\Services\ThemeService;
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
    ) {}

    /** The QR code as a PNG data URI; null for no payload. */
    public function qrPng(string $payload): ?string
    {
        $png = $this->qr->png($payload);

        return $png === '' ? null : 'data:image/png;base64,'.base64_encode($png);
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
