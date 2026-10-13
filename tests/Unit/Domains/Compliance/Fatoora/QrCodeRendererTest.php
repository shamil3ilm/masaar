<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Compliance\Fatoora;

use App\Domains\Compliance\Fatoora\Services\QrCodeRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The QR has to be a picture before anyone can scan it.
 *
 * The invoice stores its TLV payload as base64 and the screen printed the
 * string, which is correct data and no use to a customer holding a phone.
 *
 * SVG and server-side: it prints at any size without a raster asset, needs no
 * JavaScript, and so survives a content policy that allows no inline script.
 */
class QrCodeRendererTest extends TestCase
{
    public function test_it_renders_an_svg(): void
    {
        $svg = (new QrCodeRenderer)->svg(base64_encode('AQVTZWxsZXI='));

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }

    public function test_an_empty_payload_renders_nothing(): void
    {
        $this->assertSame('', (new QrCodeRenderer)->svg(''));
    }

    public function test_whitespace_renders_nothing(): void
    {
        $this->assertSame('', (new QrCodeRenderer)->svg('   '));
    }
}
