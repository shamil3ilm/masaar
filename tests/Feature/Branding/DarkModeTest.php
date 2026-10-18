<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Exceptions\InvalidThemeException;
use App\Domains\Branding\Services\BrandAssets;
use App\Domains\Branding\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Light, dark, or whatever the device prefers.
 *
 * The theme's mode puts a class on <html> that the stylesheet's dark variant
 * reads, and every screen carries a dark counterpart beside its light colours.
 * The printed invoice is for paper and stays light.
 */
class DarkModeTest extends TestCase
{
    use RefreshDatabase;

    private const VIEWS = __DIR__.'/../../../resources/views';

    /**
     * Light utilities that must not appear without a dark counterpart beside
     * them: the surfaces and text that make up most of every screen.
     */
    private const NEEDS_DARK = '/(?<![\w:\/-])(?!(?:[a-z-]+:)*dark:)((?:[a-z-]+:)*)(bg-white|bg-gray-(?:50|100|200)|text-gray-(?:400|500|600|700|800|900)|border-gray-(?:200|300))(?![\w\/-])(?!\s+dark:)/';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(BrandAssets::DISK);
    }

    public function test_the_mode_marks_the_page(): void
    {
        // The sign-in page carries its direction on <html> too now, so the
        // class is matched within the tag rather than as the whole tag.
        $this->assertMatchesRegularExpression('/<html lang="en"[^>]* class="">/', $this->get('/login')->getContent());

        $this->mode('dark');
        $this->assertMatchesRegularExpression('/<html lang="en"[^>]* class="dark">/', $this->get('/login')->getContent());

        $this->mode('system');
        $this->assertMatchesRegularExpression('/<html lang="en"[^>]* class="theme-system">/', $this->get('/login')->getContent());
    }

    public function test_dark_colours_apply_when_dark(): void
    {
        app(ThemeService::class)->save(null, ThemeDefinition::fromArray([
            'colors' => ['primary' => '#0D9488'],
            'dark' => ['colors' => ['primary' => '#2DD4BF']],
        ]));

        $css = $this->get('/theme/platform/stylesheet')->getContent();

        $this->assertStringContainsString(':root {', $css);
        $this->assertStringContainsString(':root.dark {', $css);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        $this->assertStringContainsString(':root.theme-system {', $css);
    }

    public function test_the_dark_section_is_checked(): void
    {
        $json = ['dark' => ['colors' => ['primary' => '#2DD4BF'], 'shades' => ['primary-600' => '#14B8A6']]];
        $this->assertSame($json, ThemeDefinition::fromArray($json)->toArray());

        $this->expectException(InvalidThemeException::class);
        ThemeDefinition::fromArray(['dark' => ['colors' => ['primary' => 'red'], 'name' => 'x']]);
    }

    /**
     * A ratchet on the views: a light surface or text colour with no dark
     * counterpart beside it is a screen that would turn unreadable in dark
     * mode. The printed invoice is exempt, being for paper.
     */
    public function test_every_screen_has_dark_counterparts(): void
    {
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', (string) $file);

            // The print view and the layouts it draws, in print/ folders.
            if (! str_ends_with($path, '.blade.php') || str_ends_with($path, 'portal/invoices/print.blade.php') || str_contains($path, '/print/')) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            // Comments describe classes; they do not use them.
            $contents = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $contents);

            if (preg_match_all(self::NEEDS_DARK, $contents, $matches) > 0) {
                $missing[] = substr($path, strlen(str_replace('\\', '/', self::VIEWS)) + 1).': '.implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame([], $missing, 'These light colours have no dark: counterpart beside them.');
    }

    private function mode(string $mode): void
    {
        app(ThemeService::class)->save(null, ThemeDefinition::fromArray(['mode' => $mode]));
    }
}
