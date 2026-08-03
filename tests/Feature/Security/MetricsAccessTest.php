<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Platform\Http\Middleware\RestrictMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * Regression guard for H-6 — /api/metrics carried only a rate limit, publishing
 * application and PHP version, APP_ENV, and business telemetry (invoice counts,
 * submission states, ZATCA error rates, queue depth) to anyone.
 */
class MetricsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_production_deployment_denies_access(): void
    {
        config(['metrics.token' => null, 'metrics.allowed_ips' => []]);
        app()->detectEnvironment(fn () => 'production');

        $this->get('/api/metrics')->assertForbidden();
    }

    public function test_token_is_required_once_configured(): void
    {
        config(['metrics.token' => 'scrape-token', 'metrics.allowed_ips' => []]);

        $this->get('/api/metrics')->assertForbidden();
    }

    public function test_wrong_token_is_denied(): void
    {
        config(['metrics.token' => 'scrape-token', 'metrics.allowed_ips' => []]);

        $this->withToken('not-the-token')->get('/api/metrics')->assertForbidden();
    }

    public function test_non_allowlisted_source_ip_is_denied(): void
    {
        config(['metrics.token' => null, 'metrics.allowed_ips' => ['10.0.0.5']]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.6'])
            ->get('/api/metrics')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Admit paths
    |--------------------------------------------------------------------------
    |
    | Exercised against the middleware rather than the route: MetricsController
    | reads queue depth through the Redis facade, so a full request needs the
    | phpredis extension present. Coupling an access-control test to that would
    | make it fail for reasons that have nothing to do with access control.
    |
    */

    public function test_correct_token_is_admitted(): void
    {
        config(['metrics.token' => 'scrape-token', 'metrics.allowed_ips' => []]);

        $request = Request::create('/api/metrics', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer scrape-token',
        ]);

        $this->assertTrue($this->passesGate($request));
    }

    public function test_allowlisted_source_ip_is_admitted(): void
    {
        config(['metrics.token' => null, 'metrics.allowed_ips' => ['10.0.0.5']]);

        $request = Request::create('/api/metrics', 'GET', server: ['REMOTE_ADDR' => '10.0.0.5']);

        $this->assertTrue($this->passesGate($request));
    }

    public function test_non_production_deployment_without_configuration_is_admitted(): void
    {
        config(['metrics.token' => null, 'metrics.allowed_ips' => []]);

        $this->assertTrue($this->passesGate(Request::create('/api/metrics', 'GET')));
    }

    /**
     * Whether RestrictMetrics passes the request through to the route.
     */
    private function passesGate(Request $request): bool
    {
        $reached = false;

        $response = (new RestrictMetrics)->handle($request, function () use (&$reached) {
            $reached = true;

            return new Response('metrics');
        });

        $this->assertSame($reached, $response->getStatusCode() === 200);

        return $reached;
    }
}
