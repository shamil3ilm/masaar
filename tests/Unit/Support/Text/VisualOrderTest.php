<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Text;

use App\Support\Text\VisualOrder;
use PHPUnit\Framework\TestCase;

/**
 * A line in the order it is drawn left to right, by the Unicode
 * Bidirectional Algorithm.
 */
class VisualOrderTest extends TestCase
{
    public function test_latin_stays_as_written(): void
    {
        $this->assertSame('Total due 115.00 SAR', (new VisualOrder)->visual('Total due 115.00 SAR'));
    }

    public function test_arabic_is_drawn_reversed(): void
    {
        $this->assertSame('مقر', (new VisualOrder)->visual('رقم'));
        $this->assertTrue((new VisualOrder)->isRtl('رقم 12'));
        $this->assertFalse((new VisualOrder)->isRtl('12 VAT رقم'));
    }

    /**
     * Numbers and Latin words inside Arabic keep their own order, at the
     * place the Arabic puts them.
     */
    public function test_numbers_inside_arabic_read_forwards(): void
    {
        $order = new VisualOrder;

        $this->assertSame('500 مارغ', $order->visual('غرام 500'));
        $this->assertSame('SAR 15.75 غلبم', $order->visual('مبلغ 15.75 SAR'));
    }

    public function test_arabic_inside_latin_is_reversed(): void
    {
        $this->assertSame('VAT ةبيرضلا', (new VisualOrder)->visual('VAT الضريبة'));
    }

    /** A bracket drawn right to left is mirrored, so it still opens. */
    public function test_brackets_are_mirrored(): void
    {
        $this->assertSame('(بأ)', (new VisualOrder)->visual('(أب)'));
    }

    /** A mark stays after its letter, where a zero-width glyph is drawn over it. */
    public function test_marks_follow_their_letter(): void
    {
        $this->assertSame('ابَ', (new VisualOrder)->visual('بَا'));
    }
}
