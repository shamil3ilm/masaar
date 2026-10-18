<?php

declare(strict_types=1);

namespace App\Support;

use GdImage;

/**
 * A picture as ESC/POS commands: what a receipt printer is sent to print it,
 * cut the paper and open the cash drawer.
 *
 * Raster only (GS v 0), so the printer needs no code page or font: Arabic
 * reaches it already drawn, as dots. Any image works; a dark pixel is a dot.
 * The commands are the common subset Epson defined and nearly every receipt
 * printer follows.
 */
class EscPos
{
    /** ESC @: back to the printer's defaults. */
    private const INITIALISE = "\x1B\x40";

    /** Rows per GS v 0 command: printers' buffers differ, and this fits all. */
    private const BAND = 256;

    /** Lines fed before the cut, so the last printed line clears the blade. */
    private const FEED_LINES = 4;

    /** GS V B 0: feed to the cutter and cut, leaving a hinge of paper. */
    private const CUT = "\x1D\x56\x42\x00";

    /**
     * The commands for $image: initialise, the picture, then the cut and the
     * drawer kick when asked for.
     *
     * @param  int|null  $drawer  the drawer connector's pin, 2 or 5; null for none
     */
    public function print(GdImage $image, bool $cut = true, ?int $drawer = null): string
    {
        $bytes = self::INITIALISE.$this->raster($image);

        if ($cut) {
            $bytes .= "\x1B\x64".chr(self::FEED_LINES).self::CUT;
        }

        if ($drawer !== null) {
            // ESC p m t1 t2: a pulse on pin 2 (m=0) or 5 (m=1), 50 ms on and
            // 500 ms off, in the 2 ms units the command counts in.
            $bytes .= "\x1B\x70".($drawer === 5 ? "\x01" : "\x00")."\x19\xFA";
        }

        return $bytes;
    }

    /**
     * GS v 0 commands for the whole picture, a band of rows at a time, each
     * row packed eight dots to a byte, the leftmost dot the highest bit.
     */
    public function raster(GdImage $image): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $rowBytes = intdiv($width + 7, 8);
        $dark = $this->darkColours($image);
        $bytes = '';

        for ($top = 0; $top < $height; $top += self::BAND) {
            $rows = min(self::BAND, $height - $top);
            $bytes .= "\x1D\x76\x30\x00".pack('vv', $rowBytes, $rows);

            for ($y = $top; $y < $top + $rows; $y++) {
                for ($byte = 0; $byte < $rowBytes; $byte++) {
                    $value = 0;

                    for ($bit = 0; $bit < 8; $bit++) {
                        $x = $byte * 8 + $bit;

                        if ($x < $width && $this->isDark($image, $x, $y, $dark)) {
                            $value |= 0x80 >> $bit;
                        }
                    }

                    $bytes .= chr($value);
                }
            }
        }

        return $bytes;
    }

    /**
     * For a palette image, which of its colours are dark, worked out once.
     *
     * @return array<int, bool>|null null for a truecolour image
     */
    private function darkColours(GdImage $image): ?array
    {
        if (imageistruecolor($image)) {
            return null;
        }

        $dark = [];

        for ($index = 0, $total = imagecolorstotal($image); $index < $total; $index++) {
            $dark[$index] = $this->luminance(imagecolorsforindex($image, $index)) < 128;
        }

        return $dark;
    }

    /**
     * @param  array<int, bool>|null  $dark
     */
    private function isDark(GdImage $image, int $x, int $y, ?array $dark): bool
    {
        $colour = imagecolorat($image, $x, $y);

        if ($dark !== null) {
            return $dark[$colour] ?? false;
        }

        return $this->luminance(imagecolorsforindex($image, $colour)) < 128;
    }

    /**
     * @param  array{red: int, green: int, blue: int, alpha?: int}  $rgb
     */
    private function luminance(array $rgb): float
    {
        // A transparent pixel is the paper.
        if (($rgb['alpha'] ?? 0) > 63) {
            return 255.0;
        }

        return 0.299 * $rgb['red'] + 0.587 * $rgb['green'] + 0.114 * $rgb['blue'];
    }
}
