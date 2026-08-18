<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\ApiKey;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\ValueObjects\OrganizationContext;
use App\Domains\Webhook\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closes audit finding C-4.
 *
 * Isolation used to rest on each author remembering to write
 * where('org_id', ...). Nothing failed when someone forgot, so the
 * platform's most important invariant had no structural backstop.
 * BelongsToTenant makes the safe behaviour the default.
 *
 * These tests run as HTTP rather than console, because the scope deliberately
 * stands down for commands and queue workers, which have no credential to
 * derive a tenant from.
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

    public function test_credentials_and_webhooks_are_scoped_too(): void
    {
        $this->asSystem(function () {
            ApiKey::create([
                'org_id' => $this->rival->id,
                'name' => 'Rival key',
                'key_prefix' => 'cpay_riv',
                'key_hash' => hash('sha256', 'rival-secret'),
                'scopes' => ['*'],
                'is_active' => true,
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
            $this->assertSame(0, ApiKey::count(), 'API keys leaked across tenants');
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

        $this->withoutConsole(fn () => $this->assertSame(0, Invoice::count()));
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

        return $this->withoutConsole($callback);
    }

    /**
     * Seeds data with the scope standing down, as a queue worker would.
     */
    private function asSystem(callable $callback): mixed
    {
        return $callback();
    }

    /**
     * The scope exempts console context, and the test runner is console, so
     * this flips the app into the request-like mode the scope guards.
     */
    private function withoutConsole(callable $callback): mixed
    {
        $app = app();
        $original = $app->runningInConsole();
        $app->instance('__test_running_in_console', false);

        // Laravel resolves runningInConsole() from the container's runningInConsole
        // flag; override it for the duration of the callback.
        $reflection = new \ReflectionProperty($app, 'isRunningInConsole');
        $reflection->setValue($app, false);

        try {
            return $callback();
        } finally {
            $reflection->setValue($app, $original);
        }
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
