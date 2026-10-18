<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MonochromeImage;
use PHPUnit\Framework\TestCase;

/**
 * A logo as a thermal printer prints it: black or white, transparency as the
 * paper.
 */
class MonochromeImageTest extends TestCase
{
    public function test_colours_become_black_or_white(): void
    {
        $logo = imagecreatetruecolor(40, 10);
        imagealphablending($logo, false);
        imagefilledrectangle($logo, 0, 0, 9, 9, (int) imagecolorallocate($logo, 20, 40, 120));
        imagefilledrectangle($logo, 10, 0, 19, 9, (int) imagecolorallocate($logo, 250, 220, 200));
        imagefilledrectangle($logo, 20, 0, 39, 9, (int) imagecolorallocatealpha($logo, 0, 0, 0, 127));
        imagesavealpha($logo, true);
        ob_start();
        imagepng($logo);
        $png = (string) ob_get_clean();

        $image = (new MonochromeImage)->image($png, 20);

        $this->assertNotNull($image);
        $this->assertSame(20, imagesx($image));
        $this->assertSame(0, imagecolorat($image, 2, 2) & 0xFFFFFF);
        $this->assertSame(0xFFFFFF, imagecolorat($image, 7, 2) & 0xFFFFFF);
        $this->assertSame(0xFFFFFF, imagecolorat($image, 15, 2) & 0xFFFFFF);
        $this->assertStringStartsWith('data:image/png;base64,', (string) (new MonochromeImage)->dataUri($png));
        $this->assertNull((new MonochromeImage)->image('<svg/>'));
    }
}
