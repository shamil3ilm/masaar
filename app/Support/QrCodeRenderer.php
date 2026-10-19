<?php

declare(strict_types=1);

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR payload, as something a phone can read.
 *
 * It draws whatever string it is given: an invoice's stored QR payload (for
 * Saudi Arabia, the base64 TLV string DocumentBuilder writes to
 * invoices.qr_code), or a two-factor otpauth URI. The payload is already
 * correct; this only draws it.
 *
 * No constructor arguments: there is nothing here worth injecting.
 */
class QrCodeRenderer
{
    /** Big enough to scan from a printed receipt at arm's length. */
    private const SIZE = 180;

    /** 32 mm on the page at about 475 dpi: sharp on any printer. */
    private const PRINT_SIZE = 600;

    /** The light margin a reader needs around the code, in modules (ISO/IEC 18004, 9.1). */
    public const QUIET_ZONE = 4;

    /**
     * @param  string  $encoding  how the payload's bytes are read: Latin-1, as
     *                            the country's base64 payload is, or UTF-8 for
     *                            text in Arabic, which the code then declares
     */
    public function svg(string $payload, string $encoding = Encoder::DEFAULT_BYTE_MODE_ENCODING): string
    {
        if (trim($payload) === '') {
            return '';
        }

        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(self::SIZE),
                new SvgImageBackEnd,
            )
        );

        return $writer->writeString($payload, $encoding);
    }

    /**
     * The code drawn on its own grid: one SVG unit per module, the quiet zone
     * around it, dark modules on white whatever the page's theme, and crisp
     * edges, so it stays sharp at whatever size the page sets and a phone can
     * read it from the screen. $size is its width and height in CSS pixels.
     */
    public function gridSvg(string $payload, int $size, string $encoding = Encoder::DEFAULT_BYTE_MODE_ENCODING): string
    {
        if (trim($payload) === '') {
            return '';
        }

        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::L(), $encoding)->getMatrix();
        $modules = $matrix->getWidth();
        $span = $modules + 2 * self::QUIET_ZONE;
        $path = '';

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $path .= 'M'.($x + self::QUIET_ZONE).' '.($y + self::QUIET_ZONE).'h1v1h-1z';
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %2$d %2$d" shape-rendering="crispEdges" aria-hidden="true" focusable="false">'
            .'<rect width="%2$d" height="%2$d" fill="white"/><path fill="black" d="%3$s"/></svg>',
            $size,
            $span,
            $path,
        );
    }

    /**
     * The same code as a PNG, for the invoice PDF.
     *
     * mPDF turns an SVG into a form XObject that uses graphics states it does
     * not declare in that form's own resources, and the PDF fails PDF/A
     * (ISO 19005-3, 6.2.2). A raster image carries no such references.
     */
    public function png(string $payload, string $encoding = Encoder::DEFAULT_BYTE_MODE_ENCODING): string
    {
        if (trim($payload) === '') {
            return '';
        }

        return (new Writer(new GDLibRenderer(self::PRINT_SIZE)))->writeString($payload, $encoding);
    }
}
