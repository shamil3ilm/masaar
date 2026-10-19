<?php

declare(strict_types=1);

namespace App\Domains\Branding\Services;

/**
 * A full set of shades from one brand colour.
 *
 * The interface uses a ramp of each colour - a pale tint behind a selected
 * row, a darker step for a hover - so a theme cannot be one hex value pasted
 * over another. Each ramp here is the shape of the shipped one, moved to the
 * chosen colour: the same steps in lightness and the same proportions of
 * colourfulness, around the chosen hue. That is done in OKLCH, the space the
 * stylesheet already declares its colours in, because equal steps there look
 * like equal steps.
 *
 * The shapes are the shipped palette's own: Tailwind's teal for the accent,
 * and the navy and sand of the product's mark (resources/css/app.css).
 */
final class Palette
{
    /**
     * The accent ramp, as Tailwind declares teal: step => [lightness, chroma].
     * Anchored at 500, which is the brand teal exactly.
     */
    private const PRIMARY = [
        '50' => [0.984, 0.014], '100' => [0.953, 0.051], '200' => [0.910, 0.096],
        '300' => [0.855, 0.138], '400' => [0.777, 0.152], '500' => [0.704, 0.140],
        '600' => [0.600, 0.118], '700' => [0.511, 0.096], '800' => [0.437, 0.078],
        '900' => [0.386, 0.063], '950' => [0.277, 0.046],
    ];

    /** The navy of the mark, anchored at 900. */
    private const NAVIGATION = ['950' => '#10131f', '900' => '#1a1f36', '800' => '#252c48', '700' => '#333c5c'];

    /** The sand of the mark, anchored at 200. */
    private const HIGHLIGHT = ['200' => '#eadbc8', '100' => '#f5ece1'];

    /**
     * The stylesheet variables a colour sets, and the step it is anchored at.
     */
    public const RAMPS = [
        'primary' => ['variable' => 'teal', 'anchor' => '500'],
        'navigation' => ['variable' => 'ink', 'anchor' => '900'],
        'highlight' => ['variable' => 'sand', 'anchor' => '200'],
    ];

    /**
     * Every step of a ramp, as CSS colour values.
     *
     * @param  'primary'|'navigation'|'highlight'  $ramp
     * @return array<string, string> step => oklch(...)
     */
    public function ramp(string $ramp, string $hex): array
    {
        $shape = $this->shape($ramp);
        $anchor = self::RAMPS[$ramp]['anchor'];
        [$baseL, $baseC, $baseH] = $this->oklch($hex);
        [$anchorL, $anchorC] = $shape[$anchor];

        $steps = [];

        foreach ($shape as $step => [$l, $c]) {
            $lightness = max(0.05, min(0.99, $baseL + ($l - $anchorL)));
            $chroma = $anchorC > 0.0001 ? $baseC * ($c / $anchorC) : $baseC;

            $steps[(string) $step] = sprintf('oklch(%.2f%% %.4f %.3f)', $lightness * 100, max(0.0, $chroma), $baseH);
        }

        return $steps;
    }

    /**
     * Steps a ramp has, for validating a pinned shade.
     *
     * @return list<string>
     */
    public function steps(string $ramp): array
    {
        return array_map('strval', array_keys($this->shape($ramp)));
    }

    /**
     * A hex colour in OKLCH: lightness 0-1, chroma, hue in degrees.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function oklch(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $linear = array_map(function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))]);

        [$r, $g, $b] = $linear;

        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $hue = rad2deg(atan2($bb, $a));

        return [$lightness, sqrt($a * $a + $bb * $bb), $hue < 0 ? $hue + 360 : $hue];
    }

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    private function shape(string $ramp): array
    {
        return match ($ramp) {
            'primary' => self::PRIMARY,
            'navigation' => $this->fromHex(self::NAVIGATION),
            'highlight' => $this->fromHex(self::HIGHLIGHT),
            default => throw new \InvalidArgumentException("No such ramp: {$ramp}"),
        };
    }

    /**
     * @param  array<string, string>  $hexes
     * @return array<string, array{0: float, 1: float}>
     */
    private function fromHex(array $hexes): array
    {
        return array_map(function (string $hex): array {
            [$l, $c] = $this->oklch($hex);

            return [$l, $c];
        }, $hexes);
    }
}
