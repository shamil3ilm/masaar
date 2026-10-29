<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Platform\Http\Middleware\Deprecated;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A route marked for removal says so on every answer, as the deprecation
 * policy in docs/integration/API.md promises: Deprecation (RFC 9745) since
 * when, Sunset (RFC 8594) until when, and its successor where there is one;
 * and the OpenAPI description marks the operation deprecated. No route is
 * marked today, so the mechanism is proved on one made here.
 */
class DeprecationHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(Deprecated::class.':2026-11-01,2027-05-01,/api/v1/pipeline/submit')
            ->get('/api/v1/test-deprecated', fn () => response()->json(['success' => true]));
        Route::middleware(Deprecated::class.':2026-11-01,2027-05-01')
            ->get('/api/v1/test-deprecated-alone', fn () => response()->json(['success' => true]));
    }

    public function test_a_marked_route_announces_its_sunset(): void
    {
        $this->getJson('/api/v1/test-deprecated')
            ->assertOk()
            ->assertHeader('Deprecation', '@1793491200')
            ->assertHeader('Sunset', 'Sat, 01 May 2027 00:00:00 GMT')
            ->assertHeader('Link', '<'.url('/api/v1/pipeline/submit').'>; rel="successor-version"');

        $this->getJson('/api/v1/test-deprecated-alone')->assertHeader('Sunset')->assertHeaderMissing('Link');
    }

    public function test_an_unmarked_route_announces_nothing(): void
    {
        $this->getJson('/api/health')->assertHeaderMissing('Deprecation')->assertHeaderMissing('Sunset');
    }

    public function test_the_description_marks_it(): void
    {
        Artisan::call('platform:openapi', ['--print' => true]);
        $operation = Yaml::parse(Artisan::output())['paths']['/api/v1/test-deprecated']['get'];

        $this->assertTrue($operation['deprecated']);
        $this->assertStringContainsString('Sunset 2027-05-01', $operation['description']);
    }
}
