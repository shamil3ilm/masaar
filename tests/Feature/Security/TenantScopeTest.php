<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\TenantScope;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\ValueObjects\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * How the tenant scope is stood down, and what it answers with no tenant.
 *
 * It was stood down by a process-wide static switch for the length of a
 * callback, so every query in it - a relation loaded, a listener fired, a
 * service called - read across tenants, not only the one the caller meant.
 * Now one query at a time says so (acrossTenants()), and nothing else in the
 * process is affected.
 *
 * And a request that has lost its tenant matched org_id IS NULL, which is
 * "no rows" only for as long as no tenant row has a null org_id.
 */
class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        foreach ([[$this->acme, 'ACME-1'], [$this->rival, 'RIVAL-1']] as [$organization, $number]) {
            Invoice::acrossTenants()->create([
                'org_id' => $organization->id, 'invoice_number' => $number, 'type' => 'simplified', 'status' => 'draft',
                'issue_date' => now()->toDateString(), 'currency' => 'SAR', 'subtotal' => 100, 'tax_amount' => 15, 'total' => 115,
            ]);
        }
    }

    public function test_request_without_tenant_matches_nothing(): void
    {
        $this->asRequest(function (): void {
            $this->assertSame(0, Invoice::count());
            $this->assertStringContainsString('1 = 0', Invoice::query()->toSql());
            $this->assertStringNotContainsString('is null', strtolower(Invoice::query()->toSql()));
        });
    }

    public function test_request_tenant_sees_its_own(): void
    {
        $this->actAs($this->acme);

        $this->asRequest(fn () => $this->assertSame(['ACME-1'], Invoice::pluck('invoice_number')->all()));
    }

    public function test_across_tenants_is_one_query(): void
    {
        $this->actAs($this->acme);

        $this->asRequest(function (): void {
            $this->assertSame(2, Invoice::acrossTenants()->count());
            $this->assertSame(1, Invoice::count());
        });
    }

    public function test_across_tenants_on_any_builder(): void
    {
        $this->actAs($this->acme);

        $this->asRequest(fn () => $this->assertSame(
            ['RIVAL-1'],
            Invoice::query()->where('org_id', $this->rival->id)->acrossTenants()->pluck('invoice_number')->all(),
        ));
    }

    public function test_scope_keeps_no_global_state(): void
    {
        $this->assertSame([], (new ReflectionClass(TenantScope::class))->getStaticProperties());
    }

    private function actAs(Organization $organization): void
    {
        app(TenantResolver::class)->setContext(OrganizationContext::forMachine($organization->id));
    }
}
