<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\EscPos;
use PHPUnit\Framework\TestCase;

/**
 * A picture as the ESC/POS bytes a receipt printer is sent.
 */
class EscPosTest extends TestCase
{
    /**
     * Initialise, one GS v 0 band of whole bytes per row - leftmost dot the
     * highest bit - then feed, cut, and a drawer pulse on pin 2.
     */
    public function test_an_image_becomes_raster_commands(): void
    {
        $image = imagecreatetruecolor(10, 2);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagesetpixel($image, 0, 0, $black);
        imagesetpixel($image, 9, 1, $black);

        $bytes = (new EscPos)->print($image, cut: true, drawer: 2);

        $this->assertSame(
            "\x1B\x40"
            ."\x1D\x76\x30\x00\x02\x00\x02\x00"."\x80\x00"."\x00\x40"
            ."\x1B\x64\x04\x1D\x56\x42\x00"
            ."\x1B\x70\x00\x19\xFA",
            $bytes,
        );
    }

    /** No cut and pin 5 when asked; a tall picture goes in bands. */
    public function test_bands_cut_and_drawer_follow_options(): void
    {
        $image = imagecreate(8, 300);
        imagecolorallocate($image, 255, 255, 255);

        $bytes = (new EscPos)->print($image, cut: false, drawer: 5);

        $this->assertSame(2, substr_count($bytes, "\x1D\x76\x30\x00"));
        $this->assertStringContainsString("\x1D\x76\x30\x00\x01\x00\x00\x01", $bytes);
        $this->assertStringContainsString("\x1D\x76\x30\x00\x01\x00\x2C\x00", $bytes);
        $this->assertStringNotContainsString("\x1D\x56", $bytes);
        $this->assertStringEndsWith("\x1B\x70\x01\x19\xFA", $bytes);
    }
}
