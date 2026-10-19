<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The quiet signs of just let me know., the business behind the product.
 *
 * Public pages only, all behind one admin setting, and never on anything a
 * customer issues or works in: an invoice, its PDF, its print view, an
 * email, or a portal or console screen.
 */
class ParentBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_found_invites_a_question(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Couldn&#039;t find that.', false)
            ->assertSee('just let me know')
            ->assertSee('what you were looking for')
            ->assertSee(url('/').'#contact', false);
    }

    public function test_the_footer_period_is_labelled(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<a href="https://justletmeknow.app" rel="noopener" aria-label="just let me know\."[^>]*>\.</a>#',
            (string) $html
        );
        $this->assertStringContainsString('data-easter-eggs', (string) $html);
    }

    public function test_the_setting_hides_every_sign(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->post('/admin/settings', ['site.easter_eggs' => '0'])->assertRedirect();
        $this->assertFalse(app(Settings::class)->get('site.easter_eggs'));

        auth()->logout();

        $home = (string) $this->get('/')->getContent();
        $this->assertStringNotContainsString('justletmeknow', $home);
        $this->assertStringNotContainsString('data-easter-eggs', $home);

        $missing = (string) $this->get('/no-such-page')->getContent();
        $this->assertStringNotContainsString('just let me know', $missing);
        $this->assertStringNotContainsString('data-easter-eggs', $missing);
        $this->assertStringContainsString('Tell us what you were looking for.', $missing);
    }

    /**
     * The views a customer issues or works in never carry a sign, whatever
     * the setting says.
     */
    public function test_no_sign_on_documents_or_screens(): void
    {
        $views = resource_path('views');
        $found = [];

        foreach (['pdf', 'emails', 'portal', 'admin', 'countries', 'layouts/portal.blade.php', 'layouts/admin.blade.php', 'layouts/guest.blade.php'] as $path) {
            $files = is_dir("{$views}/{$path}")
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$views}/{$path}", \FilesystemIterator::SKIP_DOTS))
                : [new \SplFileInfo("{$views}/{$path}")];

            foreach ($files as $file) {
                $source = (string) file_get_contents($file->getPathname());

                foreach (['x-parent-brand', 'justletmeknow', 'data-easter-eggs'] as $sign) {
                    if (str_contains($source, $sign)) {
                        $found[] = $file->getFilename().': '.$sign;
                    }
                }
            }
        }

        $this->assertSame([], $found);
    }
}
