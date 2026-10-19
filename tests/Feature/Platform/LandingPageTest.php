<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The first page anyone sees says what this is and belongs to this product.
 *
 * It was Laravel's stock welcome page: the framework's own logo, its
 * documentation links, a typeface fetched from fonts.bunny.net and sixty
 * kilobytes of pre-compiled Tailwind inlined in a <style> block. For a
 * compliance platform the front door announced the framework and not the
 * product.
 *
 * The test it had was Laravel's stock one too - assertStatus(200) and nothing
 * else, which is satisfied by a blank page. That matters more here than
 * usual: AttributeReadTest scans app/ and not Blade, so a mistyped variable
 * or a prop a component does not declare renders as empty space rather than
 * failing, and a page asserted only on its status code would never say so.
 * Everything below is asserted on content for that reason.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_mark_and_the_product_render(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(Brand::name());

        // The logo is drawn inline, so its geometry is in the response or it
        // did not render. A component whose props go unread leaves no trace
        // other than a missing shape.
        $response->assertSee('viewBox="0 0 120 120"', false);
        $response->assertSee('#14B8A6', false);
        $response->assertSee('#1A1F36', false);
    }

    /**
     * No third party is contacted to draw it.
     *
     * The stylesheet it used to fetch was found by a scanner reporting a
     * missing integrity attribute, and it had been there since the page was
     * generated. ExternalScriptTest holds the same line over the source;
     * this holds it over what is actually sent, which is where a helper or a
     * layout could still introduce one.
     */
    public function test_nothing_is_fetched_from_elsewhere(): void
    {
        $body = $this->get('/')->getContent();

        $this->assertIsString($body);

        // What the browser fetches: a src, or a <link>'s href. An <a> is a
        // link someone may choose to follow, and fetches nothing.
        preg_match_all('/(?:\bsrc=|<link\b[^>]*\bhref=)["\'](https?:\/\/[^"\']+)["\']/i', $body, $matches);

        // route() and asset() render absolute URLs on this application's own
        // host, so the test is about the host and not about the scheme.
        $elsewhere = array_values(array_filter(
            array_unique($matches[1]),
            fn (string $url): bool => parse_url($url, PHP_URL_HOST) !== parse_url(config('app.url'), PHP_URL_HOST)
        ));

        $this->assertSame([], $elsewhere, 'The landing page reaches out to: '
            .implode(', ', $elsewhere).'. Every visitor\'s address is handed to '
            .'whoever serves it, the first paint waits on their uptime, and the '
            .'content policy has to name them.');
    }

    public function test_a_guest_is_offered_sign_in(): void
    {
        $response = $this->get('/');

        $response->assertSee('Sign in');
        $response->assertSee(route('login'), false);
        $response->assertDontSee('Admin console');
    }

    /**
     * Someone already signed in is sent on rather than asked to sign in
     * again, and only a platform administrator is shown the console.
     */
    public function test_the_console_is_offered_to_admins(): void
    {
        $response = $this->actingAs(User::factory()->create([
            'is_platform_admin' => true,
        ]))->get('/');

        $response->assertSee('Open the portal');
        $response->assertSee('Admin console');
        $response->assertDontSee('Sign in');
    }

    /**
     * Setting it up is said in our own voice, and the contact topic matches,
     * under the key links already carry.
     */
    public function test_we_set_it_up_with_you(): void
    {
        $this->get('/')
            ->assertSee('We set it up with you')
            ->assertSee('we migrate your data;')
            ->assertSee('<option value="engineer" >Set it up with us</option>', false)
            ->assertDontSee('An engineer with your team');
    }

    public function test_an_ordinary_user_sees_no_console(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertSee('Open the portal');
        $response->assertDontSee('Admin console');
    }
}
