<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\ValueObjects\OrganizationContext;
use App\Domains\Webhook\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One taxpayer must never see another taxpayer's invoices.
 *
 * BelongsToTenant makes that the default for every query rather than
 * something each author has to remember, since a forgotten where clause
 * fails silently and leaks.
 *
 * These run as HTTP rather than console: the scope deliberately stands down
 * for commands and queue workers, which carry no credential to derive a
 * tenant from.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
    }

    public function test_invoices_scoped(): void
    {
        $this->asSystem(fn () => $this->makeInvoice($this->acme->id, 'ACME-1'));
        $this->asSystem(fn () => $this->makeInvoice($this->rival->id, 'RIVAL-1'));

        $this->asTenant($this->acme->id, function () {
            $numbers = Invoice::pluck('invoice_number')->all();

            $this->assertSame(['ACME-1'], $numbers);
        });
    }

    public function test_other_tenant_invoice_denied(): void
    {
        $rivalInvoice = $this->asSystem(fn () => $this->makeInvoice($this->rival->id, 'RIVAL-1'));

        $this->asTenant($this->acme->id, function () use ($rivalInvoice) {
            $this->assertNull(Invoice::find($rivalInvoice->id));
        });
    }

    /**
     * Aggregates leak just as effectively as row reads.
     */
    public function test_counts_do_not_include_other_tenants(): void
    {
        $this->asSystem(function () {
            $this->makeInvoice($this->acme->id, 'ACME-1');
            $this->makeInvoice($this->rival->id, 'RIVAL-1');
            $this->makeInvoice($this->rival->id, 'RIVAL-2');
        });

        $this->asTenant($this->acme->id, fn () => $this->assertSame(1, Invoice::count()));
    }

    public function test_branches_and_webhooks_scoped(): void
    {
        $this->asSystem(function () {
            Branch::create([
                'org_id' => $this->rival->id,
                'name' => 'Rival Jeddah',
                'device_serial' => '1-Masaar|2-1.0|3-rival',
                'street' => 'Rival Street',
                'building_number' => '9999',
                'district' => 'Rival District',
                'city' => 'Jeddah',
                'postal_code' => '54321',
            ]);
            Webhook::create([
                'org_id' => $this->rival->id,
                'url' => 'https://rival.example/hook',
                'events' => ['invoice.cleared'],
                'secret' => 'shh',
                'is_active' => true,
            ]);
        });

        $this->asTenant($this->acme->id, function () {
            $this->assertSame(0, Branch::count(), 'Branches leaked across tenants');
            $this->assertSame(0, Webhook::count(), 'Webhooks leaked across tenants');
        });
    }

    /**
     * A request whose tenant context is missing must return nothing, not
     * everything. Fail closed.
     */
    public function test_missing_tenant_context_yields_no_rows(): void
    {
        $this->asSystem(fn () => $this->makeInvoice($this->acme->id, 'ACME-1'));

        $this->asRequest(fn () => $this->assertSame(0, Invoice::count()));
    }

    /**
     * New records inherit the active tenant, so a caller cannot create a row
     * its own queries would then be unable to see.
     */
    public function test_created_records_inherit_the_active_tenant(): void
    {
        $this->asTenant($this->acme->id, function () {
            $invoice = $this->makeInvoice(null, 'ACME-AUTO');

            $this->assertSame($this->acme->id, $invoice->org_id);
        });
    }

    /**
     * Cross-tenant reads remain possible, but only when asked for explicitly.
     */
    public function test_scope_can_be_suspended_deliberately(): void
    {
        $this->asSystem(function () {
            $this->makeInvoice($this->acme->id, 'ACME-1');
            $this->makeInvoice($this->rival->id, 'RIVAL-1');
        });

        $this->asTenant($this->acme->id, function () {
            $this->assertSame(1, Invoice::count());
            $this->assertSame(2, Invoice::withoutTenantScope(fn () => Invoice::count()));
        });
    }

    /**
     * Runs a callback with the scope active and a given tenant.
     */
    private function asTenant(string $organizationId, callable $callback): mixed
    {
        app(TenantResolver::class)->setContext(OrganizationContext::forMachine($organizationId));

        return $this->asRequest($callback);
    }

    /**
     * Seeds data with the scope standing down, as a queue worker would.
     */
    private function asSystem(callable $callback): mixed
    {
        return $callback();
    }

    private function makeInvoice(?string $organizationId, string $number): Invoice
    {
        $attributes = [
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ];

        if ($organizationId !== null) {
            $attributes['org_id'] = $organizationId;
        }

        return Invoice::create($attributes);
    }
}
