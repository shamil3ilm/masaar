<?php

declare(strict_types=1);

namespace App\Support\Text;

use IntlChar;
use Normalizer;

/**
 * Arabic letters in the forms they take in a word, for a renderer that draws
 * one character at a time.
 *
 * Arabic is joined: a letter is drawn one way alone, another at the start of
 * a word, in its middle, and at its end, and lam followed by alef becomes a
 * single ligature. A text layout engine does this from the font's own tables;
 * GD - the only image library the application has - does not, and draws
 * every letter in its isolated form, unjoined and unreadable.
 *
 * Unicode keeps every one of those forms as a character of its own, the
 * Arabic Presentation Forms, and the fonts in use carry them. So this
 * replaces each letter with the form its neighbours call for, following
 * Unicode's joining rules (the Arabic shaping chapter of the standard, and
 * each character's Joining_Type), and the renderer draws the result as it
 * is. The tables come from ICU through intl rather than being written out
 * here: which letters join on which side, and which presentation form is
 * which letter in which position.
 *
 * Text stays in logical order. VisualOrder puts a line in display order
 * after it is wrapped.
 */
class ArabicShaper
{
    private const ISOLATED = 0;

    private const FINAL = 1;

    private const INITIAL = 2;

    private const MEDIAL = 3;

    private const LAM = 0x0644;

    /**
     * The alefs lam joins into its one required ligature with: madda, hamza
     * above, hamza below, plain. Every other ligature is the font's choice
     * and left alone.
     */
    private const ALEFS = [0x0622, 0x0623, 0x0625, 0x0627];

    /**
     * Base letter => form => presentation form; lam + alef => form => ligature.
     *
     * @var array<int, array<int, int>>|null
     */
    private static ?array $forms = null;

    /** @var array<string, array<int, int>> */
    private static array $ligatures = [];

    public function shape(string $text): string
    {
        if (preg_match('/\p{Arabic}/u', $text) !== 1) {
            return $text;
        }

        $this->load();
        $chars = mb_str_split($text);
        $points = array_map(mb_ord(...), $chars);
        $count = count($points);
        $out = '';

        for ($i = 0; $i < $count; $i++) {
            $point = $points[$i];

            if (! isset(self::$forms[$point])) {
                $out .= $chars[$i];

                continue;
            }

            $previous = $this->neighbour($points, $i, -1);
            $next = $this->neighbour($points, $i, 1);
            $joinsBefore = $previous !== null && $this->joinsForward($points[$previous]) && $this->joinsBackward($point);

            // Lam then alef is one letter, whose only forms are alone and final.
            if ($point === self::LAM && $next === $i + 1 && isset(self::$ligatures[$chars[$i].$chars[$next]])) {
                $ligature = self::$ligatures[$chars[$i].$chars[$next]];
                $out .= mb_chr($ligature[$joinsBefore ? self::FINAL : self::ISOLATED] ?? $ligature[self::ISOLATED]);
                $i = $next;

                continue;
            }

            $joinsAfter = $next !== null && $this->joinsForward($point) && $this->joinsBackward($points[$next]);
            $form = match (true) {
                $joinsBefore && $joinsAfter => self::MEDIAL,
                $joinsBefore => self::FINAL,
                $joinsAfter => self::INITIAL,
                default => self::ISOLATED,
            };

            $out .= mb_chr($this->formOf($point, $form));
        }

        return $out;
    }

    /**
     * The presentation form, or the nearest the font has: a medial form
     * missing falls back to the final, an initial to the isolated.
     */
    private function formOf(int $point, int $form): int
    {
        $forms = self::$forms[$point];

        return $forms[$form]
            ?? $forms[$form === self::MEDIAL ? self::FINAL : ($form === self::INITIAL ? self::ISOLATED : -1)]
            ?? $forms[self::ISOLATED]
            ?? $point;
    }

    /**
     * The nearest letter in a direction, passing over marks (harakat),
     * which are transparent to joining.
     *
     * @param  list<int>  $points
     */
    private function neighbour(array $points, int $index, int $step): ?int
    {
        for ($i = $index + $step; isset($points[$i]); $i += $step) {
            if ($this->joiningType($points[$i]) !== IntlChar::JT_TRANSPARENT) {
                return $i;
            }
        }

        return null;
    }

    /** Joins the letter after it: dual-joining, left-joining or a tatweel. */
    private function joinsForward(int $point): bool
    {
        return in_array($this->joiningType($point), [IntlChar::JT_DUAL_JOINING, IntlChar::JT_LEFT_JOINING, IntlChar::JT_JOIN_CAUSING], true);
    }

    /** Joins the letter before it: dual-joining, right-joining or a tatweel. */
    private function joinsBackward(int $point): bool
    {
        return in_array($this->joiningType($point), [IntlChar::JT_DUAL_JOINING, IntlChar::JT_RIGHT_JOINING, IntlChar::JT_JOIN_CAUSING], true);
    }

    private function joiningType(int $point): int
    {
        return (int) IntlChar::getIntPropertyValue($point, IntlChar::PROPERTY_JOINING_TYPE);
    }

    /**
     * Every presentation form, by the letter it is a form of: the two
     * blocks of them, each character's decomposition giving its letter and
     * its position. Forms B (FE70-FEFF) after Forms A, so where both have
     * the letter, B's - the one fonts are sure to carry - wins.
     */
    private function load(): void
    {
        if (self::$forms !== null) {
            return;
        }

        $positions = [
            IntlChar::DT_ISOLATED => self::ISOLATED,
            IntlChar::DT_FINAL => self::FINAL,
            IntlChar::DT_INITIAL => self::INITIAL,
            IntlChar::DT_MEDIAL => self::MEDIAL,
        ];
        $forms = [];

        foreach ([[0xFB50, 0xFDFF], [0xFE70, 0xFEFF]] as [$from, $to]) {
            for ($point = $from; $point <= $to; $point++) {
                $position = $positions[(int) IntlChar::getIntPropertyValue($point, IntlChar::PROPERTY_DECOMPOSITION_TYPE)] ?? null;
                $base = $position === null ? false : Normalizer::normalize(mb_chr($point), Normalizer::FORM_KC);

                if (! is_string($base)) {
                    continue;
                }

                $letters = mb_str_split($base);

                if (count($letters) === 1 && $this->joiningType(mb_ord($letters[0])) !== IntlChar::JT_TRANSPARENT) {
                    $forms[mb_ord($letters[0])][$position] = $point;
                } elseif (count($letters) === 2 && mb_ord($letters[0]) === self::LAM && in_array(mb_ord($letters[1]), self::ALEFS, true)) {
                    self::$ligatures[$base][$position] = $point;
                }
            }
        }

        self::$forms = $forms;
    }
}
