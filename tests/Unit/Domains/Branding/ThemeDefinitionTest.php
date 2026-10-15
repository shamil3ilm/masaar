<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Branding;

use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Exceptions\InvalidThemeException;
use App\Domains\Branding\Services\Palette;
use PHPUnit\Framework\TestCase;

/**
 * The theme JSON, and the ramps its colours become.
 */
class ThemeDefinitionTest extends TestCase
{
    public function test_a_theme_round_trips_as_json(): void
    {
        $json = [
            'name' => 'Northwind',
            'mode' => 'system',
            'colors' => ['primary' => '#2563EB'],
            'shades' => ['primary-600' => '#1D4ED8'],
        ];

        $this->assertSame($json, ThemeDefinition::fromArray($json)->toArray());
    }

    /**
     * A colour reaches a stylesheet, so it is six hex digits or nothing.
     */
    public function test_a_colour_cannot_carry_css(): void
    {
        try {
            ThemeDefinition::fromArray([
                'colors' => ['primary' => 'red;} body{display:none'],
                'shades' => ['primary-601' => '#000000', 'navigation-900' => 'url(x)'],
                'mode' => 'neon',
                'logo' => 'x',
            ]);
            $this->fail('An unsafe theme was accepted.');
        } catch (InvalidThemeException $e) {
            $this->assertCount(5, $e->errors);
        }
    }

    /**
     * What an organization leaves out it inherits from the platform.
     */
    public function test_a_theme_inherits_what_it_omits(): void
    {
        $platform = ThemeDefinition::shipped();
        $organization = ThemeDefinition::fromArray(['colors' => ['primary' => '#7C3AED']]);

        $merged = $platform->under($organization);

        $this->assertSame('#7C3AED', $merged->colors['primary']);
        $this->assertSame('#1A1F36', $merged->colors['navigation']);
        $this->assertSame('Masaar', $merged->name);
    }

    /**
     * The chosen colour is the ramp's anchor exactly, and the ramp runs from
     * light to dark around its hue.
     */
    public function test_the_ramp_follows_the_colour(): void
    {
        $palette = new Palette;

        // #14B8A6 is oklch(70.38% 0.1230 182.5): the anchor step is that colour.
        $this->assertSame('oklch(70.38% 0.1230 182.503)', $palette->ramp('primary', '#14B8A6')['500']);

        $lightness = array_map(
            fn (string $css): float => (float) substr($css, 6),
            $palette->ramp('primary', '#7C3AED')
        );
        $sorted = $lightness;
        arsort($sorted);

        $this->assertSame(array_keys($lightness), array_keys($sorted), 'the ramp does not run light to dark');
    }
}
