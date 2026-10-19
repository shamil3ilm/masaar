<?php

declare(strict_types=1);

namespace Tests\Feature\Interface;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The parts of a control the browser would otherwise decide: which keypad a
 * phone shows for a number, and a tooltip that is never the only way to read
 * what it says.
 */
class NativeControlsTest extends TestCase
{
    public function test_an_amount_gets_the_decimal_keypad(): void
    {
        $this->assertStringContainsString('inputmode="decimal"', Blade::render('<x-ui.input type="number" name="price" step="0.01" />'));
    }

    public function test_a_count_gets_the_numeric_keypad(): void
    {
        $this->assertStringContainsString('inputmode="numeric"', Blade::render('<x-ui.input type="number" name="copies" min="1" />'));
    }

    public function test_a_given_keypad_is_kept(): void
    {
        $html = Blade::render('<x-ui.input type="number" name="rate" step="0.01" inputmode="numeric" />');

        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringNotContainsString('inputmode="decimal"', $html);
    }

    public function test_a_tooltip_describes_its_trigger(): void
    {
        $html = Blade::render('<x-ui.tooltip text="Counted from the first of the month.">Due</x-ui.tooltip>');

        $this->assertMatchesRegularExpression('/role="tooltip" id="([\w-]+)"/', $html);
        $this->assertStringContainsString('Counted from the first of the month.', $html);
        $this->assertStringContainsString('data-tooltip', $html);
    }

    public function test_a_bare_tooltip_draws_a_button(): void
    {
        $html = Blade::render('<x-ui.tooltip text="Why we ask." />');

        $this->assertMatchesRegularExpression('/<button type="button"[^>]*aria-describedby="tip-[\w-]+"/', $html);
        $this->assertStringContainsString('aria-label="'.__('More information').'"', $html);
    }
}
