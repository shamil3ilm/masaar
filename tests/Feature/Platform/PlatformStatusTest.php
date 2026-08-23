<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Services\PlatformStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The platform dashboard, run rather than authorized.
 *
 * AdminApiAccessTest already covers who may call these routes, and asserts
 * only that the answer is not 401 or 403 — so a dashboard that raised on
 * every request would satisfy it. These read the figures.
 */
class PlatformStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_counts_across_every_tenant(): void
    {
        $first = $this->orgWithInvoices('Acme', 2);
        $second = $this->orgWithInvoices('Globex', 3);

        $overview = app(PlatformStatus::class)->overview();

        $this->assertSame(2, $overview['organizations']['total']);
        $this->assertSame(
            5,
            $overview['invoices']['total'],
            'The platform overview counted one tenant, not the platform.'
        );

        $this->assertNotSame($first->id, $second->id);
    }

    /**
     * The counts are cross-tenant by design, which means they must survive
     * being asked from inside a tenant context — a global scope applied here
     * would silently reduce every figure to the caller's own organization.
     */
    public function test_counts_ignore_the_caller_tenant(): void
    {
        $this->orgWithInvoices('Acme', 2);
        $second = $this->orgWithInvoices('Globex', 3);

        $total = app(TenantResolver::class)->runAs(
            (string) $second->id,
            fn () => app(PlatformStatus::class)->overview()['invoices']['total']
        );

        $this->assertSame(5, $total, 'The platform overview was reduced to the calling tenant.');
    }

    public function test_health_reports_each_dependency(): void
    {
        $health = app(PlatformStatus::class)->health();

        $this->assertSame('healthy', $health['database']['status']);
        $this->assertSame('healthy', $health['cache']['status']);
        $this->assertArrayHasKey('pending', $health['queue']);
        $this->assertSame('healthy', $health['overall_status']);
    }

    /**
     * An unreachable database, an open circuit breaker and a backed-up queue
     * each have to reach the overall verdict, or the dashboard reports healthy
     * while the platform is not.
     */
    public function test_failures_reach_the_verdict(): void
    {
        $status = app(PlatformStatus::class);

        $this->assertSame(
            'critical',
            $this->determine($status, ['database' => ['status' => 'unhealthy']]),
            'An unreachable database did not make the platform critical.'
        );

        $this->assertSame(
            'critical',
            $this->determine($status, ['circuit_breaker' => ['state' => 'open']])
        );

        $this->assertSame(
            'warning',
            $this->determine($status, ['queue' => ['status' => 'warning']])
        );
    }

    public function test_dashboard_endpoint_answers(): void
    {
        $this->orgWithInvoices('Acme', 1);

        $user = User::factory()->platformAdmin()->create();

        $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.invoices.total', 1)
            ->assertJsonStructure([
                'data' => [
                    'organizations' => ['total', 'active', 'with_certificate'],
                    'invoices' => ['total', 'today', 'this_hour'],
                    'submissions' => ['total', 'cleared', 'reported', 'rejected', 'pending'],
                    'system' => ['circuit_breaker', 'queue_size'],
                    'generated_at',
                ],
            ]);
    }

    public function test_health_endpoint_answers(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/admin/dashboard/health')
            ->assertOk()
            ->assertJsonPath('data.overall_status', 'healthy')
            ->assertJsonStructure([
                'data' => ['circuit_breaker', 'database', 'cache', 'queue', 'checked_at'],
            ]);
    }

    private function determine(PlatformStatus $status, array $data): string
    {
        $method = new \ReflectionMethod($status, 'determineSystemHealth');

        return $method->invoke($status, $data);
    }

    private function orgWithInvoices(string $name, int $count): Organization
    {
        $organization = Organization::create(['name' => $name, 'country' => 'SA']);

        Invoice::withoutTenantScope(function () use ($organization, $count, $name) {
            for ($i = 1; $i <= $count; $i++) {
                Invoice::create([
                    'org_id' => $organization->id,
                    'invoice_number' => "{$name}-{$i}",
                    'type' => 'standard',
                    'status' => 'draft',
                    'issue_date' => now()->toDateString(),
                    'currency' => 'SAR',
                    'buyer_name' => 'Buyer',
                    'buyer_vat_number' => '399999999900003',
                    'subtotal' => '100.00',
                    'tax_amount' => '15.00',
                    'total' => '115.00',
                ]);
            }
        });

        return $organization;
    }
}
