<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MoneyFormatter;
use PHPUnit\Framework\TestCase;

class MoneyFormatterTest extends TestCase
{
    public function test_amount_formats_fixed_decimals(): void
    {
        $this->assertSame('1234.50', MoneyFormatter::amount(1234.5));
        $this->assertSame('0.00', MoneyFormatter::amount(null));
        $this->assertSame('100.125', MoneyFormatter::amount('100.125', 3));
    }

    public function test_format_formats_with_currency_suffix(): void
    {
        $this->assertSame('1,234.50 SAR', MoneyFormatter::format(1234.5, 'SAR'));
        $this->assertSame('1234.50 AED', MoneyFormatter::format(1234.5, 'AED', 2, false));
    }

    public function test_percent_formats_trimmed_percentage(): void
    {
        $this->assertSame('15%', MoneyFormatter::percent(15));
        $this->assertSame('5%', MoneyFormatter::percent('5.00'));
        $this->assertSame('7.5%', MoneyFormatter::percent(7.5));
    }
}

