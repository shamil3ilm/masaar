<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant scoping for commands and queue workers.
 *
 * Console context has no credential to derive a tenant from, so the scope
 * stands down there and every query runs across all organizations. That is
 * right for maintenance that genuinely spans tenants, and wrong for the far
 * more common job that concerns exactly one — where a forgotten filter reads
 * every taxpayer's invoices and nothing fails.
 *
 * TenantResolver::runAs() closes that gap: work that concerns one organization
 * declares it, and is then held to it as strictly as a request would be.
 */
class ConsoleTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $this->invoice($this->acme, 'ACME-1');
        $this->invoice($this->acme, 'ACME-2');
        $this->invoice($this->rival, 'RIVAL-1');
    }

    private function tenants(): TenantResolver
    {
        return app(TenantResolver::class);
    }

    /**
     * The pre-existing behaviour, kept deliberately: a command that has not
     * said which tenant it acts for still sees all of them.
     */
    public function test_console_unscoped_without_context(): void
    {
        $this->assertSame(3, Invoice::count());
    }

    public function test_run_as_scopes_to_one_tenant(): void
    {
        $numbers = $this->tenants()->runAs(
            $this->acme->id,
            fn () => Invoice::orderBy('invoice_number')->pluck('invoice_number')->all()
        );

        $this->assertSame(['ACME-1', 'ACME-2'], $numbers);
    }

    /**
     * Aggregates leak as readily as row reads, and are easier to overlook.
     */
    public function test_run_as_scopes_counts(): void
    {
        $count = $this->tenants()->runAs($this->rival->id, fn () => Invoice::count());

        $this->assertSame(1, $count);
    }

    public function test_other_tenant_row_invisible(): void
    {
        $rivalInvoice = Invoice::where('invoice_number', 'RIVAL-1')->firstOrFail();

        $found = $this->tenants()->runAs(
            $this->acme->id,
            fn () => Invoice::find($rivalInvoice->id)
        );

        $this->assertNull($found);
    }

    /**
     * A loop over tenants must not carry one iteration's context into the next,
     * which is how a per-tenant command turns into a cross-tenant one.
     */
    public function test_context_restored_after_callback(): void
    {
        $this->tenants()->runAs($this->acme->id, fn () => Invoice::count());

        $this->assertNull($this->tenants()->getOrganizationId());
        $this->assertSame(3, Invoice::count());
    }

    public function test_nested_run_as_restores_outer(): void
    {
        $this->tenants()->runAs($this->acme->id, function () {
            $this->tenants()->runAs($this->rival->id, fn () => Invoice::count());

            $this->assertSame($this->acme->id, $this->tenants()->getOrganizationId());
        });
    }

    /**
     * Context is restored even when the work throws, or one failing tenant
     * would silently widen the scope for every tenant after it.
     */
    public function test_context_restored_after_exception(): void
    {
        try {
            $this->tenants()->runAs($this->acme->id, function () {
                throw new \RuntimeException('processing failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($this->tenants()->getOrganizationId());
    }

    private function invoice(Organization $organization, string $number): Invoice
    {
        return Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);
    }
}
