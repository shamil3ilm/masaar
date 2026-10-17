<?php

declare(strict_types=1);

namespace App\Support;

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
