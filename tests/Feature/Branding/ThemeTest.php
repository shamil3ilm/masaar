<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Domains\Auth\Models\User;
use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Services\BrandAssets;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * The platform's look, and an organization's over it.
 *
 * Colours, a name, a logo and a favicon, set by a platform administrator for
 * every page and by an organization's administrator for its own portal and the
 * invoices it prints. Applied as a stylesheet of colour variables, so nothing
 * customised means nothing changes.
 */
class ThemeTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(BrandAssets::DISK);
        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_nothing_set_changes_nothing(): void
    {
        $this->get('/theme/platform/stylesheet')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertDontSee('--color-');
    }

    public function test_the_platform_sets_the_colours(): void
    {
        $this->asAdmin()->post('/admin/branding', $this->form(['primary' => '#7C3AED']))
            ->assertRedirect(route('admin.branding.edit'));

        $css = $this->get('/theme/platform/stylesheet')->assertOk()->getContent();

        $this->assertStringContainsString('--color-teal-500: oklch(', $css);
        $this->assertStringNotContainsString('--color-ink-', $css, 'a colour left to inherit was written out');
    }

    /**
     * An organization's colours lay over the platform's on its pages only.
     */
    public function test_an_organization_overrides_its_own(): void
    {
        $themes = app(ThemeService::class);
        $themes->save(null, ThemeDefinition::fromArray(['colors' => ['primary' => '#7C3AED']]));
        $themes->save($this->acme->id, ThemeDefinition::fromArray(['colors' => ['navigation' => '#0F172A'], 'name' => 'Acme Books']));

        $theirs = $this->get("/theme/{$this->acme->id}/stylesheet")->getContent();
        $this->assertStringContainsString('--color-teal-500', $theirs);
        $this->assertStringContainsString('--color-ink-900', $theirs);

        $this->assertStringNotContainsString('--color-ink-900', $this->get('/theme/platform/stylesheet')->getContent());

        $this->asMember('admin')->get('/portal')
            ->assertOk()
            ->assertSee('Acme Books')
            ->assertSee("/theme/{$this->acme->id}/stylesheet", false);
    }

    public function test_only_an_org_admin_edits_branding(): void
    {
        $this->asMember('member')->get('/portal/branding')->assertForbidden();
        $this->asMember('member')->post('/portal/branding', $this->form(['primary' => '#000000']))->assertForbidden();

        $this->asMember('admin')->get('/portal/branding')->assertOk()->assertSee('Branding');
        $this->asMember('admin')->post('/portal/branding', $this->form(['primary' => '#000000']))
            ->assertRedirect(route('portal.branding.edit'));
    }

    /**
     * The organization's own logo is printed on its invoices; the platform's
     * never is.
     */
    public function test_the_invoice_carries_its_issuers_logo(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-1', ['type' => 'simplified', 'status' => 'issued']);

        app(ThemeService::class)->save(null, ThemeDefinition::empty(), ['logo' => $this->png()]);
        $this->asMember('admin')->get("/portal/invoices/{$invoice->id}/print")
            ->assertOk()
            ->assertDontSee("/theme/{$this->acme->id}/logo", false);

        $this->asMember('admin')->post('/portal/branding', [...$this->form([]), 'logo' => $this->png()]);

        $this->asMember('admin')->get("/portal/invoices/{$invoice->id}/print")
            ->assertSee("/theme/{$this->acme->id}/logo", false);

        $this->get("/theme/{$this->acme->id}/logo")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * An SVG is a document that can run script. One that tries is refused,
     * and any SVG served is sandboxed.
     */
    public function test_an_active_svg_is_refused(): void
    {
        $bad = UploadedFile::fake()->createWithContent('logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>');

        $this->asAdmin()->post('/admin/branding', [...$this->form([]), 'logo' => $bad])
            ->assertSessionHasErrors('theme');

        $good = UploadedFile::fake()->createWithContent('logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#123456"/></svg>');

        $this->asAdmin()->post('/admin/branding', [...$this->form([]), 'logo' => $good])->assertSessionHasNoErrors();

        $this->get('/theme/platform/logo')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }

    public function test_the_theme_travels_as_json(): void
    {
        $json = '{"name":"Northwind","colors":{"primary":"#2563EB"},"shades":{"primary-600":"#1D4ED8"}}';

        $this->asAdmin()->post('/admin/branding/import', ['theme_json' => $json])
            ->assertRedirect(route('admin.branding.edit'));

        $this->assertStringContainsString('--color-teal-600: #1D4ED8', $this->get('/theme/platform/stylesheet')->getContent());

        $exported = $this->asAdmin()->get('/admin/branding/export')->assertOk()->streamedContent();
        $this->assertSame(json_decode($json, true), json_decode($exported, true));

        $this->asAdmin()->post('/admin/branding/import', ['theme_json' => '{"colors":{"primary":"red;}"}}'])
            ->assertSessionHasErrors('theme_json');
    }

    public function test_the_editors_render(): void
    {
        app(ThemeService::class)->save(null, ThemeDefinition::fromArray(['colors' => ['primary' => '#7C3AED']]), ['logo' => $this->png()]);

        $this->asAdmin()->get('/admin/branding')->assertOk()->assertSee('#7C3AED');
        $this->asMember('admin')->get('/portal/branding')->assertOk()->assertSee('Download theme.json');
    }

    public function test_reset_returns_to_inheriting(): void
    {
        $this->asAdmin()->post('/admin/branding', $this->form(['primary' => '#7C3AED']));
        $this->asAdmin()->post('/admin/branding/reset')->assertRedirect(route('admin.branding.edit'));

        $this->assertStringNotContainsString('--color-', $this->get('/theme/platform/stylesheet')->getContent());
    }

    /**
     * @param  array<string, string>  $colors  only these are ticked as custom
     * @return array<string, mixed>
     */
    private function form(array $colors): array
    {
        $custom = [];
        $values = [];

        foreach (['primary', 'navigation', 'highlight'] as $key) {
            $custom[$key] = array_key_exists($key, $colors) ? '1' : '0';
            $values[$key] = $colors[$key] ?? '#000000';
        }

        return ['name' => '', 'mode' => '', 'custom' => $custom, 'colors' => $values];
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->image('logo.png', 120, 40);
    }

    private function asAdmin(): static
    {
        return $this->actingAs(User::factory()->create(['is_platform_admin' => true]));
    }

    private function asMember(string $role): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
