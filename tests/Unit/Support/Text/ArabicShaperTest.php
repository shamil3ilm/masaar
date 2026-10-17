<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Text;

use App\Support\Text\ArabicShaper;
use PHPUnit\Framework\TestCase;

/**
 * Arabic letters in the form their place in the word calls for, so a
 * renderer drawing one character at a time draws joined script.
 */
class ArabicShaperTest extends TestCase
{
    public function test_letters_take_their_positions(): void
    {
        // Meem initial, hah medial, meem medial, dal final.
        $this->assertSame($this->chars(0xFEE3, 0xFEA4, 0xFEE4, 0xFEAA), (new ArabicShaper)->shape('محمد'));
    }

    /**
     * Lam then alef is one ligature, final after a joining letter and
     * isolated after one that does not join forward.
     */
    public function test_lam_alef_is_one_ligature(): void
    {
        $shaper = new ArabicShaper;

        // Seen initial, lam-alef final, meem isolated.
        $this->assertSame($this->chars(0xFEB3, 0xFEFC, 0xFEE1), $shaper->shape('سلام'));
        // Alef isolated, then lam with hamza-below alef, isolated.
        $this->assertSame($this->chars(0xFE8D, 0xFEF9), $shaper->shape('الإ'));
    }

    /**
     * A letter that joins only backwards ends the joining: reh is final, and
     * the beh after it starts again.
     */
    public function test_right_joining_letters_break_words(): void
    {
        // Beh initial, reh final, beh isolated.
        $this->assertSame($this->chars(0xFE91, 0xFEAE, 0xFE8F), (new ArabicShaper)->shape('برب'));
    }

    /** Marks are transparent: the letters either side still join. */
    public function test_marks_do_not_break_joining(): void
    {
        $this->assertSame($this->chars(0xFE91).'َ'.$this->chars(0xFE8E), (new ArabicShaper)->shape('بَا'));
    }

    public function test_other_text_is_untouched(): void
    {
        $this->assertSame('INV-2026 115.00 SAR', (new ArabicShaper)->shape('INV-2026 115.00 SAR'));
        $this->assertSame('VAT '.$this->chars(0xFEA9), (new ArabicShaper)->shape('VAT د'));
    }

    private function chars(int ...$points): string
    {
        return implode('', array_map(mb_chr(...), $points));
    }
}
