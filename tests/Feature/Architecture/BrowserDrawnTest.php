<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Nothing the browser draws is left in the browser's own style.
 *
 * A scrollbar, an autofilled field, a date picker, a file button, a colour
 * well and a validation bubble are each drawn by the browser unless a page
 * says otherwise - in the platform's grey, its typography and its language,
 * which is English on an Arabic page more often than not. The design system
 * says otherwise once, in resources/css/app.css and the x-ui components;
 * these keep a screen from going back to the native one.
 */
class BrowserDrawnTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function test_the_stylesheet_colours_native_parts(): void
    {
        $css = (string) file_get_contents(self::ROOT.'/resources/css/app.css');

        foreach (['scrollbar-color:', 'scrollbar-width:', '::-webkit-scrollbar-thumb', ':-webkit-autofill', 'accent-color:', 'caret-color:', '::selection', ':focus-visible'] as $rule) {
            $this->assertStringContainsString($rule, $css, "resources/css/app.css no longer styles {$rule}.");
        }
    }
}
