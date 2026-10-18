<?php

declare(strict_types=1);

namespace App\Support;

use GdImage;

/**
 * A picture as a thermal printer prints it: every pixel black or white.
 *
 * A thermal head burns a dot or leaves the paper, so a colour logo or an
 * anti-aliased edge is printed by the driver's own guess at a pattern of
 * dots. Deciding here, at a threshold, keeps a receipt's logo the same
 * through the browser, the PDF and the printer image.
 *
 * GD alone: no other image library is available to the application.
 */
class MonochromeImage
{
    /** Below this luminance (of 255) a pixel is black. */
    private const THRESHOLD = 160;

    /**
     * The image flattened onto white and thresholded, no wider than
     * $maxWidth; null when the bytes are not an image GD reads.
     */
    public function image(string $contents, ?int $maxWidth = null): ?GdImage
    {
        // Known by their first bytes, so what GD cannot read - an SVG logo -
        // is a null rather than a warning.
        $source = preg_match('/^(\x89PNG|\xFF\xD8\xFF|GIF8|RIFF....WEBP|BM)/s', $contents) === 1 ? imagecreatefromstring($contents) : false;

        if ($source === false) {
            return null;
        }

        if ($maxWidth !== null && imagesx($source) > $maxWidth) {
            $scaled = imagescale($source, $maxWidth);
            $source = $scaled === false ? $source : $scaled;
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $image = imagecreatetruecolor($width, $height);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);

        // Transparency becomes the paper, not black.
        imagefill($image, 0, 0, $white);
        imagealphablending($image, true);
        imagecopy($image, $source, 0, 0, 0, 0, $width, $height);

        $this->threshold($image, $white, $black);

        return $image;
    }

    /**
     * The same as a two-colour PNG data URI, for the browser and the PDF.
     */
    public function dataUri(string $contents, ?int $maxWidth = null): ?string
    {
        $image = $this->image($contents, $maxWidth);

        if ($image === null) {
            return null;
        }

        imagetruecolortopalette($image, false, 2);
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * Every pixel of a truecolour image to black or white, in place.
     */
    public function threshold(GdImage $image, ?int $white = null, ?int $black = null): void
    {
        $white ??= (int) imagecolorallocate($image, 255, 255, 255);
        $black ??= (int) imagecolorallocate($image, 0, 0, 0);

        for ($y = 0, $height = imagesy($image); $y < $height; $y++) {
            for ($x = 0, $width = imagesx($image); $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $luminance = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                imagesetpixel($image, $x, $y, $luminance < self::THRESHOLD ? $black : $white);
            }
        }
    }
}
