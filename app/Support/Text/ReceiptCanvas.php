<?php

declare(strict_types=1);

namespace App\Support\Text;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use GdImage;

/**
 * A strip of thermal paper to draw a receipt on, one line under the next,
 * at the printer's own resolution: one pixel is one dot of the head.
 *
 * Text goes through ArabicShaper and VisualOrder, so Arabic reaches GD
 * joined and in display order; GD then draws it glyph by glyph from the
 * font. Everything is black on white, and image() leaves nothing else: a
 * thermal head prints a dot or none.
 *
 * Sizes are in points and lengths in millimetres, as on paper; the dpi turns
 * them into dots.
 */
class ReceiptCanvas
{
    public const LEFT = 'left';

    public const RIGHT = 'right';

    public const CENTER = 'center';

    /** The tallest receipt drawn, in dots: about 2.5 m at 203 dpi. */
    private const MAX_HEIGHT = 20000;

    /** GD sizes type at 96 dpi. */
    private const GD_DPI = 96;

    private const FONT_DIR = 'vendor/mpdf/mpdf/ttfonts/';

    private GdImage $image;

    private int $black;

    private int $y = 0;

    private readonly int $padding;

    public function __construct(
        private readonly int $width,
        private readonly int $dpi,
        private readonly ArabicShaper $shaper = new ArabicShaper,
        private readonly VisualOrder $order = new VisualOrder,
    ) {
        $this->image = imagecreatetruecolor($width, self::MAX_HEIGHT);
        imagefill($this->image, 0, 0, (int) imagecolorallocate($this->image, 255, 255, 255));
        $this->black = (int) imagecolorallocate($this->image, 0, 0, 0);
        $this->padding = $this->dots(0.5);
    }

    public function dots(float $mm): int
    {
        return (int) round($mm / 25.4 * $this->dpi);
    }

    public function space(float $mm): void
    {
        $this->y += $this->dots($mm);
    }

    /**
     * A paragraph, wrapped to the width: right-aligned when it reads right
     * to left and no alignment is given.
     */
    public function text(string $text, float $points, bool $bold = false, ?string $align = null, ?bool $rtl = null): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $rtl ??= $this->order->isRtl($text);
        $align ??= $rtl ? self::RIGHT : self::LEFT;

        foreach ($this->wrap($this->shaper->shape($text), $points, $bold, $this->width - 2 * $this->padding) as $line) {
            $this->line([[$line, $align, $rtl]], $points, $bold);
        }
    }

    /**
     * Two texts on one line, one at each side - a caption and its amount -
     * or one under the other when they do not fit side by side.
     *
     * @param  bool|null  $startRtl  the first text's direction, when its first
     *                               letter does not decide it: a quantity whose
     *                               unit is in Arabic still reads left to right
     */
    public function pair(string $start, string $end, float $points, bool $bold = false, ?bool $startRtl = null): void
    {
        $start = $this->shaper->shape(trim($start));
        $end = $this->shaper->shape(trim($end));
        $startRtl ??= $this->order->isRtl($start);
        $gap = $this->dots(2);

        if ($this->measure($start, $points, $bold) + $gap + $this->measure($end, $points, $bold) <= $this->width - 2 * $this->padding) {
            $this->line([[$start, self::LEFT, $startRtl], [$end, self::RIGHT, $this->order->isRtl($end)]], $points, $bold);

            return;
        }

        $this->text($start, $points, $bold, self::LEFT, $startRtl);
        $this->text($end, $points, $bold, self::RIGHT);
    }

    /** A dashed rule across the paper. */
    public function rule(): void
    {
        $this->space(1.2);
        $dash = $this->dots(1.5);
        $thickness = max(2, $this->dots(0.25));

        for ($x = $this->padding; $x < $this->width - $this->padding; $x += 2 * $dash) {
            imagefilledrectangle($this->image, $x, $this->y, min($x + $dash, $this->width - $this->padding) - 1, $this->y + $thickness - 1, $this->black);
        }

        $this->y += $thickness;
        $this->space(1.2);
    }

    /**
     * A QR code, centred, as near $mm wide as whole dots per module allow:
     * a module of uneven dots is what makes a printed QR unreadable. Encoded
     * as QrCodeRenderer encodes it, so it holds the same data.
     */
    public function qr(string $payload, float $mm, string $encoding = Encoder::DEFAULT_BYTE_MODE_ENCODING): void
    {
        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::L(), $encoding)->getMatrix();
        $modules = $matrix->getWidth();
        // Four modules of white around it, the quiet zone a reader needs.
        $module = max(2, (int) round($this->dots($mm) / ($modules + 8)));

        while ($module > 1 && ($modules + 8) * $module > $this->width) {
            $module--;
        }

        $left = intdiv($this->width - $modules * $module, 2);
        $top = $this->y + 4 * $module;

        for ($row = 0; $row < $modules; $row++) {
            for ($column = 0; $column < $modules; $column++) {
                if ($matrix->get($column, $row) === 1) {
                    imagefilledrectangle(
                        $this->image,
                        $left + $column * $module,
                        $top + $row * $module,
                        $left + ($column + 1) * $module - 1,
                        $top + ($row + 1) * $module - 1,
                        $this->black,
                    );
                }
            }
        }

        $this->y = $top + ($modules + 4) * $module;
    }

    /** A black-and-white picture, centred, no wider than the paper. */
    public function picture(GdImage $picture): void
    {
        $width = min(imagesx($picture), $this->width - 2 * $this->padding);
        $height = (int) round(imagesy($picture) * $width / max(1, imagesx($picture)));

        imagecopyresampled($this->image, $picture, intdiv($this->width - $width, 2), $this->y, 0, 0, $width, $height, imagesx($picture), imagesy($picture));
        $this->y += $height;
    }

    /**
     * What was drawn, cropped to its length and reduced to two colours:
     * every grey an anti-aliased edge left becomes black or white.
     */
    public function image(float $bottomMm = 3.0): GdImage
    {
        $height = min(self::MAX_HEIGHT, $this->y + $this->dots($bottomMm));
        $image = imagecrop($this->image, ['x' => 0, 'y' => 0, 'width' => $this->width, 'height' => max(1, $height)]) ?: $this->image;

        imagetruecolortopalette($image, false, 2);

        for ($index = 0, $colours = imagecolorstotal($image); $index < $colours; $index++) {
            $rgb = imagecolorsforindex($image, $index);
            $dark = 0.299 * $rgb['red'] + 0.587 * $rgb['green'] + 0.114 * $rgb['blue'] < 128;
            imagecolorset($image, $index, $dark ? 0 : 255, $dark ? 0 : 255, $dark ? 0 : 255);
        }

        return $image;
    }

    /**
     * One line of up to two pieces, each placed at its side and drawn in
     * display order.
     *
     * @param  list<array{0: string, 1: string, 2: bool}>  $pieces  shaped text, alignment, right to left
     */
    private function line(array $pieces, float $points, bool $bold): void
    {
        $size = $this->gdSize($points);
        $ascent = (int) ceil($size * self::GD_DPI / 72 * 0.95);
        $lineHeight = (int) ceil($size * self::GD_DPI / 72 * 1.3);

        foreach ($pieces as [$text, $align, $rtl]) {
            $visual = $this->order->visual($text, $rtl);
            $box = imagettfbbox($size, 0, $this->font($bold), $visual) ?: [0, 0, 0, 0, 0, 0, 0, 0];
            $width = $box[2] - $box[0];
            $x = match ($align) {
                self::RIGHT => $this->width - $this->padding - $width - $box[0],
                self::CENTER => intdiv($this->width - $width, 2) - $box[0],
                default => $this->padding - $box[0],
            };

            imagettftext($this->image, $size, 0, (int) $x, $this->y + $ascent, $this->black, $this->font($bold), $visual);
        }

        $this->y += $lineHeight;
    }

    /**
     * Lines no wider than $width: broken between words, and within a word
     * only when the word alone is too wide - a hash, a long number.
     *
     * @return list<string>
     */
    private function wrap(string $text, float $points, bool $bold, int $width): array
    {
        $lines = [];
        $current = '';

        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($this->measure($candidate, $points, $bold) <= $width) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            $current = '';

            foreach (mb_str_split($word) as $char) {
                if ($current !== '' && $this->measure($current.$char, $points, $bold) > $width) {
                    $lines[] = $current;
                    $current = '';
                }

                $current .= $char;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function measure(string $text, float $points, bool $bold): int
    {
        $box = imagettfbbox($this->gdSize($points), 0, $this->font($bold), $text) ?: [0, 0, 0, 0];

        return $box[2] - $box[0];
    }

    /** The size GD is given for type of $points on this printer. */
    private function gdSize(float $points): float
    {
        return $points * $this->dpi / self::GD_DPI;
    }

    /**
     * DejaVu Sans, one of the fonts the PDF renderer ships: it has Latin,
     * Arabic and every Arabic presentation form ArabicShaper may ask for.
     */
    private function font(bool $bold): string
    {
        return base_path(self::FONT_DIR.($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf'));
    }
}
