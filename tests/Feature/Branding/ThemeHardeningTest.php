<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Domains\Auth\Models\User;
use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Exceptions\InvalidThemeException;
use App\Domains\Branding\Models\Theme;
use App\Domains\Branding\Services\BrandAssets;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a review of the branding feature found, each pinned down.
 */
class ThemeHardeningTest extends TestCase
{
    use RefreshDatabase;

    private ThemeService $themes;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(BrandAssets::DISK);
        $this->themes = app(ThemeService::class);
    }

    /**
     * Saved, reset, saved again: a new URL, or a browser that cached the
     * first for a year keeps the old look.
     */
    public function test_a_version_is_never_reused(): void
    {
        $this->themes->save(null, ThemeDefinition::fromArray(['colors' => ['primary' => '#7C3AED']]));
        $first = $this->themes->forScope(ThemeService::PLATFORM)->stylesheetUrl;

        $this->themes->reset(null);
        $this->themes->save(null, ThemeDefinition::fromArray(['colors' => ['primary' => '#2563EB']]));

        $this->assertNotSame($first, $this->themes->forScope(ThemeService::PLATFORM)->stylesheetUrl);
    }

    public function test_saving_the_form_keeps_dark_colours(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->post('/admin/branding/import', [
            'theme_json' => '{"dark":{"colors":{"primary":"#2DD4BF"}}}',
        ]);
        $this->actingAs($admin)->post('/admin/branding', [
            'name' => 'Renamed', 'mode' => '', 'custom' => ['primary' => '0', 'navigation' => '0', 'highlight' => '0'],
            'colors' => ['primary' => '#000000', 'navigation' => '#000000', 'highlight' => '#000000'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('#2DD4BF', $this->themes->storedDefinition(null)->dark?->colors['primary']);
    }

    /**
     * A stored theme that no longer validates is ignored, not fatal - sign-in
     * and the editor that can reset it still open.
     */
    public function test_a_broken_stored_theme_is_survived(): void
    {
        DB::table('themes')->insert([
            'id' => (string) Str::uuid(), 'scope' => 'platform', 'org_id' => null,
            'definition' => json_encode(['colors' => ['primary' => 'red']]), 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/login')->assertOk();
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))->get('/admin/branding')->assertOk();
    }

    /**
     * Only real ids, and nothing remembered for ids with no theme.
     */
    public function test_unknown_scopes_leave_nothing_behind(): void
    {
        $this->get('/theme/'.str_repeat('a', 36).'/stylesheet')->assertNotFound();

        $unknown = (string) Str::uuid();
        $this->get("/theme/{$unknown}/stylesheet")->assertOk();

        $this->assertFalse(Cache::has('theme:'.$unknown));
    }

    /**
     * An organization's colour is its whole ramp: a platform's pinned shade
     * or dark colour for that ramp does not show through.
     */
    public function test_an_organization_owns_its_ramp(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->themes->save(null, ThemeDefinition::fromArray([
            'colors' => ['primary' => '#14B8A6'],
            'shades' => ['primary-600' => '#ABCDEF'],
            'dark' => ['colors' => ['primary' => '#2DD4BF']],
        ]));
        $this->themes->save($acme->id, ThemeDefinition::fromArray(['colors' => ['primary' => '#7C3AED']]));

        $css = $this->get("/theme/{$acme->id}/stylesheet")->getContent();

        $this->assertStringNotContainsString('#ABCDEF', $css);
        $this->assertStringNotContainsString(':root.dark', $css);
    }

    public function test_the_platform_has_one_theme(): void
    {
        $this->themes->save(null, ThemeDefinition::fromArray(['name' => 'One']));
        $this->themes->save(null, ThemeDefinition::fromArray(['name' => 'Two']));

        $this->assertSame(1, Theme::query()->where('scope', 'platform')->count());
    }

    /**
     * A refused second upload takes the first one with it.
     */
    public function test_a_failed_save_leaves_no_files(): void
    {
        try {
            $this->themes->save(null, ThemeDefinition::empty(), [
                'logo' => UploadedFile::fake()->image('logo.png', 40, 40),
                'favicon' => UploadedFile::fake()->createWithContent('fav.png', 'not an image'),
            ]);
            $this->fail('An unreadable favicon was accepted.');
        } catch (InvalidThemeException) {
        }

        $this->assertSame([], Storage::disk(BrandAssets::DISK)->allFiles());
    }

    public function test_a_deleted_organizations_files_go(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->themes->save($acme->id, ThemeDefinition::empty(), ['logo' => UploadedFile::fake()->image('logo.png', 40, 40)]);

        $acme->delete();

        $this->assertSame([], Storage::disk(BrandAssets::DISK)->allFiles($acme->id));
    }

    /**
     * Ways past the SVG checks, each refused.
     */
    public function test_svg_bypasses_are_refused(): void
    {
        $svgs = [
            'prefixed href' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:x="http://www.w3.org/1999/xlink"><image x:href="https://evil.example/p.png"/></svg>',
            'tab in scheme' => '<svg xmlns="http://www.w3.org/2000/svg"><a href="#x" style="background:java&#9;script:alert(1)"/></svg>',
            'quoted url' => '<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill:url( \'https://evil.example/x\' )"/></svg>',
            'style element' => '<svg xmlns="http://www.w3.org/2000/svg"><style>rect{fill:url(//evil.example/x)}</style></svg>',
            'padded doctype' => '<?xml version="1.0"?><!--'.str_repeat('x', 5000).'--><!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"/>',
        ];

        foreach ($svgs as $case => $svg) {
            try {
                (new BrandAssets)->store(UploadedFile::fake()->createWithContent('logo.svg', $svg), 'platform', 'logo');
                $this->fail("An SVG with a {$case} was accepted.");
            } catch (InvalidThemeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_trailing_newline_is_refused(): void
    {
        $this->expectException(InvalidThemeException::class);

        ThemeDefinition::fromArray(['colors' => ['primary' => "#0D9488\n"]]);
    }
}
