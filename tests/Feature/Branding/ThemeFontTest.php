<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Domains\Auth\Models\User;
use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Exceptions\InvalidThemeException;
use App\Domains\Branding\Services\BrandAssets;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The typeface, chosen like the colours: the platform's for every page, an
 * organization's over it on its own.
 *
 * The choice is between the faces the application bundles and the device's
 * own, and nothing else - a font name reaches a stylesheet, and a typeface
 * from anywhere but this origin is refused by the content policy anyway.
 */
class ThemeFontTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(BrandAssets::DISK);
        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_the_font_travels_as_json(): void
    {
        $json = ['font' => 'system'];

        $this->assertSame($json, ThemeDefinition::fromArray($json)->toArray());
    }

    public function test_an_unknown_font_is_refused(): void
    {
        $this->expectException(InvalidThemeException::class);

        ThemeDefinition::fromArray(['font' => "Comic Sans'; } body { display: none"]);
    }

    public function test_the_shipped_font_writes_nothing(): void
    {
        $this->assertStringNotContainsString('--font-', $this->get('/theme/platform/stylesheet')->getContent());
    }

    public function test_the_platform_picks_the_font(): void
    {
        $this->asAdmin()->post('/admin/branding', $this->form('system'))
            ->assertRedirect(route('admin.branding.edit'));

        $css = $this->get('/theme/platform/stylesheet')->getContent();

        $this->assertStringContainsString('--font-latin: system-ui;', $css);
        $this->assertStringContainsString('--font-arabic: system-ui;', $css);
        $this->assertStringNotContainsString('--color-', $css, 'colours left to inherit were written out');
    }

    public function test_an_organization_overrides_the_font(): void
    {
        $themes = app(ThemeService::class);
        $themes->save(null, ThemeDefinition::fromArray(['font' => 'system']));
        $themes->save($this->acme->id, ThemeDefinition::fromArray(['font' => 'plex']));

        $theirs = $this->get("/theme/{$this->acme->id}/stylesheet")->getContent();
        $this->assertStringContainsString("--font-latin: 'IBM Plex Sans Arabic';", $theirs);

        $this->assertStringContainsString('--font-latin: system-ui;', $this->get('/theme/platform/stylesheet')->getContent());
    }

    public function test_the_editor_offers_the_fonts(): void
    {
        $this->asAdmin()->get('/admin/branding')
            ->assertOk()
            ->assertSee('name="font"', false)
            ->assertSee('value="system"', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(string $font): array
    {
        return [
            'name' => '',
            'mode' => '',
            'font' => $font,
            'custom' => ['primary' => '0', 'navigation' => '0', 'highlight' => '0'],
            'colors' => ['primary' => '#000000', 'navigation' => '#000000', 'highlight' => '#000000'],
        ];
    }

    private function asAdmin(): static
    {
        return $this->actingAs(User::factory()->create(['is_platform_admin' => true]));
    }
}
