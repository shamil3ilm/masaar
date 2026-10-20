<?php

declare(strict_types=1);

namespace Tests\Feature\Interface;

use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * An address no route matches, met signed in: the 404 is drawn through the
 * web middleware (the fallback route), so it knows who is asking and leads
 * back to their dashboard. The API's unknown addresses stay outside it and
 * answer in the JSON envelope, as before, with no session started for them.
 */
class UnknownAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_are_led_to_the_portal(): void
    {
        $response = $this->signedIn(User::factory()->create())->get('/portal/no-such-page')->assertNotFound();

        $this->assertSame(route('portal.dashboard'), $this->actions($response)[0]);
    }

    public function test_admins_are_led_to_the_console(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $response = $this->signedIn($admin)->get('/admin/no-such-page')->assertNotFound();
        $this->assertSame(route('admin.dashboard'), $this->actions($response)[0]);

        $response = $this->signedIn($admin)->get('/no-such-page')->assertNotFound();
        $this->assertSame(route('admin.dashboard'), $this->actions($response)[0]);
    }

    public function test_a_visitor_gets_no_dashboard(): void
    {
        $actions = $this->actions($this->get('/portal/no-such-page')->assertNotFound()->assertDontSee(__('Back to the dashboard')));

        $this->assertSame([url('/')], $actions);
    }

    public function test_unknown_api_addresses_answer_json(): void
    {
        foreach (['/api/no-such-thing', '/api/v1/no-such-thing', '/v1/no-such-thing'] as $path) {
            $this->get($path)
                ->assertNotFound()
                ->assertHeader('Content-Type', 'application/json')
                ->assertJsonPath('success', false)
                ->assertJsonPath('error.code', 'NOT_FOUND')
                ->assertCookieMissing(config('session.cookie'));
        }
    }

    /**
     * Signed in as a browser is: by the session cookie alone. actingAs()
     * hands the guard its user directly, so a page drawn without the web
     * middleware would still see one - which is the case this is about.
     */
    private function signedIn(User $user): static
    {
        // Kept on disk: the array driver forgets with its store, below.
        config(['session.driver' => 'file']);
        $this->app['session']->forgetDrivers();

        $session = $this->app['session']->driver();
        $session->start();
        $session->put(Auth::guard('web')->getName(), $user->getAuthIdentifier());
        $session->save();

        // Nothing kept in memory: what the request knows, it reads.
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['auth']->forgetGuards();

        return $this->withCookie(config('session.cookie'), $session->getId());
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
