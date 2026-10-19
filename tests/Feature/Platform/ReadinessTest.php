<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Platform\Services\Readiness;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Whether this instance can serve, as a load balancer asks it.
 *
 * /api/health answered "ok" whenever PHP did. That is the right question for a
 * container restart - restarting the app does not bring a database back - and
 * the wrong one for routing: an instance that cannot reach its database took
 * traffic and failed every request it was given. /api/health/ready asks the
 * dependencies, and says which one failed without saying why.
 */
class ReadinessTest extends TestCase
{
    public function test_ready_when_dependencies_answer(): void
    {
        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'checks' => ['database' => 'ok', 'cache' => 'ok'],
            ]);
    }

    public function test_a_dead_database_is_not_ready(): void
    {
        config([
            'database.connections.unreachable' => [
                'driver' => 'sqlite',
                'database' => sys_get_temp_dir().'/platform-missing-'.uniqid().'/none.sqlite',
            ],
            'database.default' => 'unreachable',
        ]);
        DB::purge('unreachable');

        $response = $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.database', 'failing');

        // The reason goes to the log, not to whoever asked.
        $this->assertStringNotContainsString('sqlite', (string) $response->getContent());
    }

    /**
     * A store that accepts a write and gives nothing back is not a cache.
     */
    public function test_a_dead_cache_is_failing(): void
    {
        config([
            'cache.stores.unreachable' => ['driver' => 'null'],
            'cache.default' => 'unreachable',
        ]);

        $this->assertFalse(app(Readiness::class)->check()['cache']);
    }
}
