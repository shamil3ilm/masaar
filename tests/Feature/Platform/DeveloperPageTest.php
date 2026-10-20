<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Platform\Developers\ApiReference;
use App\Domains\Platform\Developers\QuickStart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * /developers: public, served whole from this application under the strict
 * content security policy, and rendered from the same OpenAPI description
 * the repository publishes - the JSON copy platform:openapi writes beside
 * the YAML, held equal to it here.
 */
class DeveloperPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_read_the_page(): void
    {
        $response = $this->get('/developers')->assertOk();

        $response->assertSee('Submit an invoice')
            ->assertSee(QuickStart::SUBMIT)
            ->assertSee('X-API-Key')
            ->assertSee('invoice.cleared')
            ->assertSee('data-code-sample="python"', false);

        foreach (app(QuickStart::class)->submissions() as $submission) {
            $response->assertSee(QuickStart::json($submission['invoice']));
        }
    }

    public function test_nothing_loads_from_elsewhere(): void
    {
        $response = $this->get('/developers')->assertOk();
        $html = (string) $response->getContent();
        $self = parse_url((string) config('app.url'), PHP_URL_HOST);

        preg_match_all('/<(?:script|link|img|iframe)\b[^>]*\b(?:src|href)="(https?:)?\/\/([^\/"]+)/i', $html, $hosts);

        $elsewhere = array_diff(array_unique(array_map(fn (string $host): string => explode(':', $host)[0], $hosts[2])), [$self]);

        $this->assertNotSame([], $hosts[2], 'the page loads its own assets');
        $this->assertSame([], array_values($elsewhere), 'the page loads something from another host');
        $this->assertStringContainsString("script-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_every_partner_endpoint_is_described(): void
    {
        $html = (string) $this->get('/developers')->getContent();
        $spec = app(ApiReference::class)->spec();

        foreach (array_keys($spec['paths']) as $path) {
            $this->assertStringContainsString(e($path), $html, $path.' is not on the page');
        }
    }

    public function test_the_page_has_arabic(): void
    {
        $this->get('/developers?lang=ar')->assertOk()
            ->assertSee('<html lang="ar"', false)
            ->assertSee('<link rel="canonical" href="'.route('developers').'?lang=ar">', false);
    }

    /**
     * The page's copy is the published description, as JSON: regenerating
     * changes both or neither. It is the public part of it, each operation
     * as the YAML describes it.
     */
    public function test_the_reference_matches_the_spec(): void
    {
        Artisan::call('platform:openapi', ['--print-reference' => true]);

        $this->assertSame(
            trim(str_replace("\r\n", "\n", (string) file_get_contents(base_path(ApiReference::FILE)))),
            trim(str_replace("\r\n", "\n", Artisan::output())),
            ApiReference::FILE.' is out of date with the routes. Run: php artisan platform:openapi',
        );

        $yaml = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $reference = app(ApiReference::class)->spec();
        unset($yaml['info']['title']);
        $yaml['paths'] = array_intersect_key($yaml['paths'], $reference['paths']);
        $yaml['components']['securitySchemes'] = array_intersect_key($yaml['components']['securitySchemes'], $reference['components']['securitySchemes']);

        $this->assertEquals($yaml, $reference);
    }

    /**
     * Only what a partner or a customer builds on: no console, platform,
     * probe or metrics route, and nothing behind platform.admin, on the page
     * or in the JSON it reads.
     */
    public function test_no_admin_or_platform_paths(): void
    {
        $html = (string) $this->get('/developers')->getContent();
        $reference = app(ApiReference::class)->spec();
        $json = (string) file_get_contents(base_path(ApiReference::FILE));

        $hidden = ['/api/admin/', '/api/metrics', '/api/health', '/api/peppol/webhooks/', '/api/license/status'];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('platform.admin', $route->gatherMiddleware(), true)) {
                $hidden[] = '/'.$route->uri();
            }
        }

        foreach (array_unique($hidden) as $path) {
            $this->assertStringNotContainsString('"'.$path, $json, $path.' is in the reference');
            $this->assertStringNotContainsString(e($path), $html, $path.' is on the page');

            foreach (array_keys($reference['paths']) as $described) {
                $this->assertStringStartsNotWith($path, $described);
            }
        }

        $this->assertArrayNotHasKey('metricsToken', $reference['components']['securitySchemes']);
    }

    /** The partner API whole, and the customer's own. */
    public function test_partner_and_tenant_apis_are_listed(): void
    {
        $yaml = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $described = array_keys(app(ApiReference::class)->spec()['paths']);

        foreach (array_keys($yaml['paths']) as $path) {
            if (str_starts_with($path, ApiReference::PARTNER)) {
                $this->assertContains($path, $described);
            }
        }

        foreach (['/api/auth/login', '/api/invoices', '/api/invoices/{invoice}/pdf', '/api/schedules', '/api/webhooks', '/api/license/quotas'] as $path) {
            $this->assertContains($path, $described);
        }
    }
}
