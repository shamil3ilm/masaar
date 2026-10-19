<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Templates;

/**
 * Whether a template's colour stays legible on paper.
 *
 * The colour is printed as text on white and as a fill under white text, and
 * a black-and-white printer turns it into a grey of the same luminance. So
 * one measure answers all three: its contrast with white, as WCAG 2.2
 * defines contrast (relative luminance, (L1 + 0.05) / (L2 + 0.05)), at least
 * the 4.5 to 1 that success criterion 1.4.3 asks of body text.
 *
 * A colour chosen on the template screen is refused below that; the theme's
 * colour, which was chosen for screens, is darkened until it reaches it.
 */
final class Contrast
{
    /** WCAG 2.2, success criterion 1.4.3: text of ordinary size. */
    public const MINIMUM = 4.5;

    private const WHITE = '#FFFFFF';

    public static function ratio(string $first, string $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** Whether the colour reads on white, and white reads on it. */
    public static function legible(string $hex): bool
    {
        return self::ratio($hex, self::WHITE) >= self::MINIMUM;
    }

    /**
     * The colour, or the nearest darker shade of it that is legible: the
     * same hue, mixed toward black a step at a time.
     */
    public static function legibleShade(string $hex): string
    {
        [$r, $g, $b] = self::channels($hex);

        for ($step = 0; $step <= 50; $step++) {
            $keep = 1 - $step * 0.02;
            $shade = sprintf('#%02X%02X%02X', (int) round($r * $keep), (int) round($g * $keep), (int) round($b * $keep));

            if (self::legible($shade)) {
                return $shade;
            }
        }

        return '#000000';
    }

    /**
     * The colour mixed with white, $amount of it kept: a pale tint behind
     * dark text, which stays dark enough to read on any tint.
     */
    public static function tint(string $hex, float $amount): string
    {
        [$r, $g, $b] = self::channels($hex);
        $mix = fn (int $channel): int => (int) round(255 - (255 - $channel) * $amount);

        return sprintf('#%02X%02X%02X', $mix($r), $mix($g), $mix($b));
    }

    /** WCAG 2.2's relative luminance of an sRGB colour. */
    private static function luminance(string $hex): float
    {
        $linear = array_map(function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::channels($hex));

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
