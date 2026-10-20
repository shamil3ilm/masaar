<?php

declare(strict_types=1);

namespace Tests\Feature\Interface;

use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Someone signed in who meets a refusal or a missing page inside the
 * application keeps their bearings: the page's first way on is back to
 * their own dashboard - the console's for the console, the portal's for
 * the portal - rather than the public home page.
 *
 * Read from the page's actions, not the whole page: the public layout's
 * header links both areas for a signed-in admin.
 */
class ErrorBearingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['portal', 'admin', 'account'] as $area) {
            Route::middleware('web')->get("/{$area}/_missing", fn () => abort(404));
            Route::middleware('web')->get("/{$area}/_refused", fn () => abort(403));
        }
    }

    public function test_portal_pages_lead_to_the_portal(): void
    {
        $member = User::factory()->create();

        foreach (['/portal/_missing' => 404, '/portal/_refused' => 403] as $path => $status) {
            $response = $this->actingAs($member)->get($path)->assertStatus($status)->assertSee(__('Back to the dashboard'));

            $this->assertSame(route('portal.dashboard'), $this->actions($response)[0]);
        }
    }

    public function test_console_pages_lead_to_the_console(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);

        foreach (['/admin/_missing' => 404, '/admin/_refused' => 403] as $path => $status) {
            $response = $this->actingAs($admin)->get($path)->assertStatus($status);

            $this->assertSame(route('admin.dashboard'), $this->actions($response)[0]);
        }
    }

    /** Refused the console: the way back is to where they may go. */
    public function test_a_member_refused_the_console(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/admin/_refused')->assertForbidden();

        $this->assertSame(route('portal.dashboard'), $this->actions($response)[0]);
    }

    /** Outside either area, the area that is theirs. */
    public function test_elsewhere_leads_to_their_own_area(): void
    {
        $member = $this->actingAs(User::factory()->create())->get('/account/_missing')->assertNotFound();
        $this->assertSame(route('portal.dashboard'), $this->actions($member)[0]);

        $admin = $this->actingAs(User::factory()->create(['is_platform_admin' => true]))->get('/account/_missing')->assertNotFound();
        $this->assertSame(route('admin.dashboard'), $this->actions($admin)[0]);
    }

    public function test_visitors_get_no_dashboard_link(): void
    {
        foreach (['/portal/_missing' => 404, '/portal/_refused' => 403] as $path => $status) {
            $actions = $this->actions($this->get($path)->assertStatus($status)->assertDontSee(__('Back to the dashboard')));

            $this->assertNotContains(route('portal.dashboard'), $actions);
            $this->assertNotContains(route('admin.dashboard'), $actions);
        }
    }

    /**
     * The addresses of the page's own ways on, in order.
     *
     * @return list<string>
     */
    private function actions(TestResponse $response): array
    {
        $html = (string) $response->getContent();
        $start = strpos($html, 'data-error-actions');

        $this->assertNotFalse($start, 'The page has no actions.');

        preg_match_all('/href="([^"]+)"/', substr($html, $start, strpos($html, '</div>', $start) - $start), $links);

        return $links[1];
    }
}
