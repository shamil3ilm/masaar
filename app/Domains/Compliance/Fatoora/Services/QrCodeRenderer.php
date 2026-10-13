<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

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
}
