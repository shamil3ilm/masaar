<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A token naming one organization must not reach another's records, even when
 * the caller supplies the other's identifiers directly.
 *
 * This is the assertion the platform exists to make, and until tokens carried a
 * tenant it could not be made over HTTP at all: every request was unscoped, so
 * a test like this would have been asserting against a surface that returned
 * nothing to anybody.
 *
 * Two things enforce it, and this asserts the behaviour they jointly produce
 * rather than either one: the controllers filter on the resolved tenant
 * explicitly, and BelongsToTenant puts TenantScope on the model underneath.
 * Disabling the scope alone changes nothing here, which is the point of having
 * both — but it also means a passing test says only that one of them held. It
 * fails when both are removed.
 */
class JwtCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $user = User::factory()->create(['email' => 'acme@masaar.test']);
        $user->organizations()->attach($this->acme->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'acme@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    public function test_another_tenants_invoice_is_not_found(): void
    {
        $mine = $this->invoiceFor($this->acme, 'ACME-1');
        $theirs = $this->invoiceFor($this->rival, 'RIVAL-1');

        $this->acme()->getJson("/api/invoices/{$mine->id}")->assertOk();
        $this->acme()->getJson("/api/invoices/{$theirs->id}")->assertNotFound();
    }

    public function test_another_tenants_webhook_is_not_found(): void
    {
        $mine = $this->webhookFor($this->acme);
        $theirs = $this->webhookFor($this->rival);

        $this->acme()->getJson("/api/webhooks/{$mine->id}")->assertOk();
        $this->acme()->getJson("/api/webhooks/{$theirs->id}")->assertNotFound();
    }

    /**
     * Listing is the other half: a collection endpoint must not include what
     * the show endpoint refuses.
     */
    public function test_listings_hold_only_one_tenant(): void
    {
        $this->invoiceFor($this->acme, 'ACME-1');
        $this->invoiceFor($this->rival, 'RIVAL-1');

        $numbers = array_column(
            $this->acme()->getJson('/api/invoices')->assertOk()->json('data') ?? [],
            'invoice_number'
        );

        $this->assertSame(['ACME-1'], $numbers);
    }

    /**
     * Deleting is worth its own case: a refusal that reads as "not found" must
     * not have deleted the row on its way to saying so.
     */
    public function test_another_tenants_invoice_survives_deletion(): void
    {
        $theirs = $this->invoiceFor($this->rival, 'RIVAL-1');

        $this->acme()->deleteJson("/api/invoices/{$theirs->id}")->assertNotFound();

        $this->assertNotNull(
            Invoice::withoutTenantScope(fn () => Invoice::find($theirs->id)),
            "Another tenant's invoice was deleted by a request that answered 404."
        );
    }

    /**
     * A request carrying Acme's token.
     */
    private function acme(): self
    {
        return $this->withToken($this->token);
    }

    private function invoiceFor(Organization $organization, string $number): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
        ]));
    }

    private function webhookFor(Organization $organization): Webhook
    {
        return Webhook::withoutTenantScope(fn () => Webhook::create([
            'org_id' => $organization->id,
            'url' => 'https://example.test/hook',
            'events' => ['invoice.cleared'],
            'secret' => 'shh',
            'is_active' => true,
        ]));
    }
}
