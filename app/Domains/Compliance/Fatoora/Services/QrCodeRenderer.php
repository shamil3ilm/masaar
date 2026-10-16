<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The ZATCA QR payload, as something a phone can read.
 *
 * The payload is already correct and already stored - DocumentBuilder writes
 * the base64 TLV string to invoices.qr_code. This only draws it.
 *
 * No constructor arguments: WiredDependencyTest refuses optional collaborators
 * on anything under app/*\/Services/, and there is nothing here worth
 * injecting.
 */
class QrCodeRenderer
{
    /** Big enough to scan from a printed receipt at arm's length. */
    private const SIZE = 180;

    /** 32 mm on the page at about 475 dpi: sharp on any printer. */
    private const PRINT_SIZE = 600;

    public function svg(string $payload): string
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

        return $writer->writeString($payload);
    }

    /**
     * The same code as a PNG, for the invoice PDF.
     *
     * mPDF turns an SVG into a form XObject that uses graphics states it does
     * not declare in that form's own resources, and the PDF fails PDF/A
     * (ISO 19005-3, 6.2.2). A raster image carries no such references.
     */
    public function png(string $payload): string
    {
        if (trim($payload) === '') {
            return '';
        }

        return (new Writer(new GDLibRenderer(self::PRINT_SIZE)))->writeString($payload);
    }
}
