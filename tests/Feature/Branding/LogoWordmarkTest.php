<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Domains\Branding\DTOs\ActiveTheme;
use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Http\ThemeComposer;
use App\Support\Brand;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * An uploaded logo stands for the company on its own: it is held to a
 * width a phone's header can spare, and the platform's name is not put
 * beside it unless the company named its theme.
 */
class LogoWordmarkTest extends TestCase
{
    public function test_an_uploaded_logo_stands_alone(): void
    {
        $this->theme(ThemeDefinition::empty());

        $html = Blade::render('<x-logo name />');

        $this->assertStringContainsString('max-w-32', $html);
        $this->assertStringNotContainsString('>'.Brand::name().'<', $html);
    }

    public function test_a_named_theme_keeps_its_name(): void
    {
        $this->theme(ThemeDefinition::fromArray(['name' => 'Najd Walk']));

        $this->assertStringContainsString('Najd Walk', Blade::render('<x-logo name />'));
    }

    private function theme(ThemeDefinition $definition): void
    {
        request()->attributes->set(ThemeComposer::ATTRIBUTE, new ActiveTheme(
            scope: 'organization',
            definition: $definition,
            stylesheetUrl: '/theme.css',
            faviconUrl: '/favicon.ico',
            faviconType: null,
            logoUrl: '/logo.png',
        ));
    }
}
