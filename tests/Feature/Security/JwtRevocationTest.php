<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Removing someone from an organization has to take effect now, not when their
 * token expires.
 *
 * The organization is a claim, so the token keeps asserting it for as long as
 * it is valid — an hour by default. Trusting that claim alone means a person
 * whose access was revoked carries on reading invoices and filing tax
 * documents for the rest of the hour, and the one moment anybody would want
 * revocation to be immediate is a dismissal or a breach.
 *
 * The portal already re-checks membership on every request in PortalTenant.
 * This holds the JWT surface to the same rule: the claim says which
 * organization, and the database says whether that is still allowed.
 */
class JwtRevocationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $acme;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->user = User::factory()->create(['email' => 'member@masaar.test']);
        $this->user->organizations()->attach($this->acme->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-1',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
        ]));

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'member@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    public function test_a_member_reads_their_organization(): void
    {
        $this->withToken($this->token)->getJson('/api/invoices')->assertOk();
    }

    public function test_a_removed_member_is_refused(): void
    {
        $this->user->organizations()->detach($this->acme->id);

        $this->withToken($this->token)
            ->getJson('/api/invoices')
            ->assertUnauthorized();
    }

    /**
     * Suspension is the same thing said differently, and the pivot carries it.
     */
    public function test_a_suspended_member_is_refused(): void
    {
        $this->user->organizations()->updateExistingPivot($this->acme->id, [
            'status' => 'suspended',
        ]);

        $this->withToken($this->token)
            ->getJson('/api/invoices')
            ->assertUnauthorized();
    }
}
