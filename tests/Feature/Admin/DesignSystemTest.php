<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The style guide in the admin console: every token and component, drawn by
 * the components themselves, in light and dark and in both directions.
 */
class DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENTS = [
        'alert', 'badge', 'breadcrumb', 'button', 'card', 'checkbox', 'disclosure', 'empty', 'field', 'input',
        'link-button', 'menu', 'menu-item', 'modal', 'nav-link', 'page-header', 'radio', 'section-heading', 'select', 'country-select', 'date-input', 'time-input', 'file',
        'stat', 'stepper', 'switch', 'tab-panel', 'table', 'tabs', 'textarea', 'th',
    ];

    public function test_the_style_guide_draws_four_previews(): void
    {
        $html = $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->get('/admin/design-system')
            ->assertOk()
            ->getContent();

        $this->assertSame(4, substr_count($html, 'data-theme-scope'));
        $this->assertSame(2, substr_count($html, 'dir="rtl" lang="ar"'));
        $this->assertStringContainsString('aria-label="Pages of results"', $html);
        $this->assertStringContainsString('role="switch"', $html);
        $this->assertStringContainsString('aria-current="step"', $html);
    }

    /**
     * The four kinds of confirmation, each drawn and each askable, and the
     * wording rules beside them.
     */
    public function test_it_shows_every_confirmation_kind(): void
    {
        $html = $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->get('/admin/design-system')
            ->assertOk()
            ->getContent();

        // One of each per preview: standard, danger, typed and approval.
        $this->assertSame(16, substr_count($html, 'data-confirm-detail='));
        $this->assertSame(4, substr_count($html, 'data-confirm-tone="danger"'));
        $this->assertSame(4, substr_count($html, 'data-confirm-type='));
        $this->assertSame(4, substr_count($html, 'name="approval_reason"'));
        $this->assertStringContainsString('OK, Yes or Confirm on the button.', $html);
        $this->assertStringContainsString('data-confirm-dialog', $html);
    }

    /**
     * A component added to the library is shown here too, or this fails.
     */
    public function test_every_component_has_a_specimen(): void
    {
        $specimens = (string) file_get_contents(resource_path('views/admin/partials/design-specimens.blade.php'));
        $missing = [];

        foreach (glob(resource_path('views/components/ui/*.blade.php')) ?: [] as $file) {
            $name = basename($file, '.blade.php');

            // The confirmation dialog and the scripts' words are on every
            // page already, and the pagination and flash are drawn through
            // ->links() and the layout.
            if (in_array($name, ['confirm-dialog', 'strings', 'pagination', 'flash'], true)) {
                continue;
            }

            if (! str_contains($specimens, '<x-ui.'.$name)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'These components have no specimen on the style guide.');
        $this->assertSame([], array_diff(self::COMPONENTS, array_map(fn (string $f): string => basename($f, '.blade.php'), glob(resource_path('views/components/ui/*.blade.php')) ?: [])));
    }

    /**
     * A date to the minute, or to the second where it is evidence: an
     * audit entry, a transmission record, a document's arrival.
     */
    public function test_dates_can_carry_seconds(): void
    {
        $this->assertStringContainsString('>2026-10-14 13:05:22</time>', Blade::render('<x-ui.date value="2026-10-14 13:05:22" seconds />'));
        $this->assertStringContainsString('>2026-10-14 13:05</time>', Blade::render('<x-ui.date value="2026-10-14 13:05:22" time />'));
        $this->assertStringContainsString('>2026-10-14</time>', Blade::render('<x-ui.date value="2026-10-14 13:05:22" />'));

        $html = $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->get('/admin/design-system')
            ->getContent();

        $this->assertSame(4, substr_count($html, '>2026-10-14 13:05:22</time>'), 'The style guide shows a date with seconds in each preview.');
    }

    public function test_only_the_console_sees_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/design-system')->assertForbidden();
    }
}
