<?php

declare(strict_types=1);

namespace App\Support\Text;

use IntlChar;

/**
 * One line of mixed Arabic and Latin text, in the order it is drawn left to
 * right.
 *
 * Text is stored in reading order: an Arabic word's first letter first,
 * though it is drawn rightmost. A renderer that draws characters left to
 * right - GD - needs them in display order, which is the Unicode
 * Bidirectional Algorithm's to decide (UAX #9): Arabic runs reversed,
 * numbers and Latin words inside them kept left to right, punctuation
 * between taking the direction of what surrounds it.
 *
 * The algorithm for a single line with no explicit embeddings, which invoice
 * text does not use (any embedding or isolate control is dropped): the weak
 * types (W1-W7), the neutrals (N1-N2), the implicit levels (I1-I2), trailing
 * whitespace (L1), reversal (L2), marks after their letters (L3) and mirrored
 * brackets (L4). Character types come from ICU through intl.
 */
class VisualOrder
{
    private const L = IntlChar::CHAR_DIRECTION_LEFT_TO_RIGHT;

    private const R = IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT;

    private const AL = IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT_ARABIC;

    private const EN = IntlChar::CHAR_DIRECTION_EUROPEAN_NUMBER;

    private const ES = IntlChar::CHAR_DIRECTION_EUROPEAN_NUMBER_SEPARATOR;

    private const ET = IntlChar::CHAR_DIRECTION_EUROPEAN_NUMBER_TERMINATOR;

    private const AN = IntlChar::CHAR_DIRECTION_ARABIC_NUMBER;

    private const CS = IntlChar::CHAR_DIRECTION_COMMON_NUMBER_SEPARATOR;

    private const NSM = IntlChar::CHAR_DIRECTION_DIR_NON_SPACING_MARK;

    private const WS = IntlChar::CHAR_DIRECTION_WHITE_SPACE_NEUTRAL;

    private const ON = IntlChar::CHAR_DIRECTION_OTHER_NEUTRAL;

    /** Embedding and isolate controls, and other formatting with no glyph. */
    private const DROPPED = [
        IntlChar::CHAR_DIRECTION_BOUNDARY_NEUTRAL,
        IntlChar::CHAR_DIRECTION_LEFT_TO_RIGHT_EMBEDDING,
        IntlChar::CHAR_DIRECTION_LEFT_TO_RIGHT_OVERRIDE,
        IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT_EMBEDDING,
        IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT_OVERRIDE,
        IntlChar::CHAR_DIRECTION_POP_DIRECTIONAL_FORMAT,
        IntlChar::CHAR_DIRECTION_LEFT_TO_RIGHT_ISOLATE,
        IntlChar::CHAR_DIRECTION_RIGHT_TO_LEFT_ISOLATE,
        IntlChar::CHAR_DIRECTION_FIRST_STRONG_ISOLATE,
        IntlChar::CHAR_DIRECTION_POP_DIRECTIONAL_ISOLATE,
    ];

    /** Whether a line reads right to left: its first strong character is. */
    public function isRtl(string $text): bool
    {
        foreach (mb_str_split($text) as $char) {
            $type = IntlChar::charDirection($char);

            if ($type === self::L) {
                return false;
            }

            if ($type === self::R || $type === self::AL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  bool|null  $rtl  the paragraph's direction; null to take it from the text
     */
    public function visual(string $line, ?bool $rtl = null): string
    {
        $chars = array_values(array_filter(
            mb_str_split($line),
            fn (string $char): bool => ! in_array(IntlChar::charDirection($char), self::DROPPED, true),
        ));

        if ($chars === []) {
            return '';
        }

        $base = ($rtl ?? $this->isRtl($line)) ? 1 : 0;
        $original = array_map(fn (string $char): int => (int) IntlChar::charDirection($char), $chars);
        $types = $this->resolveNeutrals($this->resolveWeak($original, $base), $base);
        $levels = $this->levels($types, $original, $base);

        return implode('', $this->reorder($chars, $levels, $original));
    }

    /**
     * W1-W7.
     *
     * @param  list<int>  $types
     * @return list<int>
     */
    private function resolveWeak(array $types, int $base): array
    {
        $sos = $base === 1 ? self::R : self::L;
        $count = count($types);

        // W1: a mark takes the type of what it follows.
        for ($i = 0; $i < $count; $i++) {
            if ($types[$i] === self::NSM) {
                $types[$i] = $i === 0 ? $sos : $types[$i - 1];
            }
        }

        // W2: a European number after Arabic letters is an Arabic number.
        // W3: Arabic letters are right-to-left.
        $strong = $sos;

        for ($i = 0; $i < $count; $i++) {
            if (in_array($types[$i], [self::L, self::R, self::AL], true)) {
                $strong = $types[$i];
            } elseif ($types[$i] === self::EN && $strong === self::AL) {
                $types[$i] = self::AN;
            }
        }

        $types = array_map(fn (int $type): int => $type === self::AL ? self::R : $type, $types);

        // W4: one separator between two numbers of a kind joins them.
        for ($i = 1; $i < $count - 1; $i++) {
            if ($types[$i] === self::ES && $types[$i - 1] === self::EN && $types[$i + 1] === self::EN) {
                $types[$i] = self::EN;
            } elseif ($types[$i] === self::CS && $types[$i - 1] === $types[$i + 1] && in_array($types[$i - 1], [self::EN, self::AN], true)) {
                $types[$i] = $types[$i - 1];
            }
        }

        // W5: currency and percent signs next to a number belong to it.
        for ($i = 0; $i < $count; $i++) {
            if ($types[$i] !== self::ET) {
                continue;
            }

            $end = $i;

            while ($end < $count && $types[$end] === self::ET) {
                $end++;
            }

            if (($i > 0 && $types[$i - 1] === self::EN) || ($end < $count && $types[$end] === self::EN)) {
                array_splice($types, $i, $end - $i, array_fill(0, $end - $i, self::EN));
            }

            $i = $end;
        }

        // W6: other separators are neutral. W7: a number in left-to-right
        // text is left-to-right.
        $strong = $sos;

        for ($i = 0; $i < $count; $i++) {
            if (in_array($types[$i], [self::ES, self::ET, self::CS], true)) {
                $types[$i] = self::ON;
            }

            if ($types[$i] === self::L || $types[$i] === self::R) {
                $strong = $types[$i];
            } elseif ($types[$i] === self::EN && $strong === self::L) {
                $types[$i] = self::L;
            }
        }

        return $types;
    }

    /**
     * N1-N2: neutrals between two of one direction take it - numbers count
     * as right-to-left here - and the rest take the line's.
     *
     * @param  list<int>  $types
     * @return list<int>
     */
    private function resolveNeutrals(array $types, int $base): array
    {
        $count = count($types);
        $direction = fn (int $type): ?int => match ($type) {
            self::L => self::L,
            self::R, self::EN, self::AN => self::R,
            default => null,
        };
        $baseType = $base === 1 ? self::R : self::L;

        for ($i = 0; $i < $count; $i++) {
            if ($direction($types[$i]) !== null) {
                continue;
            }

            $end = $i;

            while ($end < $count && $direction($types[$end]) === null) {
                $end++;
            }

            $before = $i === 0 ? $baseType : $direction($types[$i - 1]);
            $after = $end === $count ? $baseType : $direction($types[$end]);
            $resolved = $before === $after ? $before : $baseType;
            array_splice($types, $i, $end - $i, array_fill(0, $end - $i, $resolved));
            $i = $end;
        }

        return $types;
    }

    /**
     * I1-I2, and L1 for the line's trailing whitespace.
     *
     * @param  list<int>  $types
     * @param  list<int>  $original
     * @return list<int>
     */
    private function levels(array $types, array $original, int $base): array
    {
        $levels = array_map(fn (int $type): int => match (true) {
            $base === 0 && $type === self::R => 1,
            $base === 0 && ($type === self::AN || $type === self::EN) => 2,
            $base === 1 && ($type === self::L || $type === self::EN || $type === self::AN) => 2,
            default => $base,
        }, $types);

        for ($i = count($original) - 1; $i >= 0 && in_array($original[$i], [self::WS, IntlChar::CHAR_DIRECTION_SEGMENT_SEPARATOR], true); $i--) {
            $levels[$i] = $base;
        }

        return $levels;
    }

    /**
     * L2-L4: reverse every run at or above each odd level, highest first;
     * keep marks after their letter; mirror brackets drawn right to left.
     *
     * @param  list<string>  $chars
     * @param  list<int>  $levels
     * @param  list<int>  $original
     * @return list<string>
     */
    private function reorder(array $chars, array $levels, array $original): array
    {
        $count = count($chars);
        $order = range(0, $count - 1);
        $lowestOdd = min(array_map(fn (int $level): int => $level % 2 === 1 ? $level : $level + 1, $levels));

        for ($level = max($levels); $level >= $lowestOdd; $level--) {
            for ($i = 0; $i < $count; $i++) {
                if ($levels[$order[$i]] < $level) {
                    continue;
                }

                $end = $i;

                while ($end < $count && $levels[$order[$end]] >= $level) {
                    $end++;
                }

                array_splice($order, $i, $end - $i, array_reverse(array_slice($order, $i, $end - $i)));
                $i = $end;
            }
        }

        // L3: a reversed run puts a letter's marks before it; put them back.
        for ($i = 0; $i < $count; $i++) {
            $end = $i;

            while ($end < $count && $original[$order[$end]] === self::NSM && $levels[$order[$end]] % 2 === 1) {
                $end++;
            }

            if ($end > $i && $end < $count) {
                $cluster = array_merge([$order[$end]], array_slice($order, $i, $end - $i));
                array_splice($order, $i, $end - $i + 1, $cluster);
                $i = $end;
            }
        }

        return array_map(function (int $index) use ($chars, $levels): string {
            $mirror = $levels[$index] % 2 === 1 ? IntlChar::charMirror($chars[$index]) : null;

            return is_string($mirror) ? $mirror : $chars[$index];
        }, $order);
    }
}
