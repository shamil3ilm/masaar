<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Platform\Http\Middleware\MetricsAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * /api/metrics is not public.
 *
 * It discloses application and PHP version, APP_ENV, and business telemetry —
 * invoice counts, submission states, ZATCA error rates, queue depth. The
 * versions assist targeted attack; the volumes are commercially sensitive. A
 * rate limit bounds how fast that can be read, not who reads it.
 */
class MetricsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_prod_denied(): void
    {
        config(['metrics.token' => null, 'metrics.allowed_ips' => []]);
        app()->detectEnvironment(fn () => 'production');

        $this->get('/api/metrics')->assertForbidden();
    }

    public function test_token_required(): void
    {
        config(['metrics.token' => 'scrape-token', 'metrics.allowed_ips' => []]);

        $this->get('/api/metrics')->assertForbidden();
    }

    public function test_wrong_token_denied(): void
    {
        config(['metrics.token' => 'scrape-token', 'metrics.allowed_ips' => []]);

        $this->withToken('not-the-token')->get('/api/metrics')->assertForbidden();
    }

    public function test_other_ip_denied(): void
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

    public function test_valid_token_allowed(): void
    {
        config(['metrics.token' => 'scrape-token', 'metrics.allowed_ips' => []]);

        $request = Request::create('/api/metrics', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer scrape-token',
        ]);

        $this->assertTrue($this->passesGate($request));
    }

    public function test_allowed_ip_admitted(): void
    {
        config(['metrics.token' => null, 'metrics.allowed_ips' => ['10.0.0.5']]);

        $request = Request::create('/api/metrics', 'GET', server: ['REMOTE_ADDR' => '10.0.0.5']);

        $this->assertTrue($this->passesGate($request));
    }

    public function test_open_outside_production(): void
    {
        config(['metrics.token' => null, 'metrics.allowed_ips' => []]);

        $this->assertTrue($this->passesGate(Request::create('/api/metrics', 'GET')));
    }

    /**
     * Whether MetricsAccess passes the request through to the route.
     */
    private function passesGate(Request $request): bool
    {
        $reached = false;

        $response = (new MetricsAccess)->handle($request, function () use (&$reached) {
            $reached = true;

            return new Response('metrics');
        });

        $this->assertSame($reached, $response->getStatusCode() === 200);

        return $reached;
    }
}
