<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A JWT request has to say which organization it is acting for.
 *
 * JwtGuard reads org_id and role from the token's claims and establishes the
 * tenant from them, and nothing ever put them there — getJWTCustomClaims()
 * returned an empty array. So the condition was never true, no tenant was set,
 * and every route on the JWT surface ran unscoped: reads returned nothing and
 * anything asking the resolver for an organization failed outright.
 *
 * organizations/{id}/switch appeared to cover this. It set the tenant on the
 * resolver, which is request-scoped, so the choice was gone by the next
 * request and no new token carried it.
 *
 * A user belonging to exactly one organization is scoped at login, because
 * there is nothing to choose. A user belonging to several chooses, and gets a
 * token that carries the choice.
 */
class JwtTenantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $acme;

    private Organization $rival;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $this->user = User::factory()->create(['email' => 'member@example.test']);
    }

    public function test_one_organization_scopes_at_login(): void
    {
        $this->join($this->acme);

        $claims = $this->claims($this->login());

        $this->assertSame($this->acme->id, $claims['org_id'] ?? null);
        $this->assertSame('admin', $claims['role'] ?? null);
    }

    /**
     * With more than one there is nothing to infer, so the token carries no
     * organization and the caller has to choose.
     */
    public function test_several_organizations_start_unscoped(): void
    {
        $this->join($this->acme);
        $this->join($this->rival);

        $this->assertArrayNotHasKey('org_id', $this->claims($this->login()));
    }

    public function test_switching_issues_a_scoped_token(): void
    {
        $this->join($this->acme);
        $this->join($this->rival, 'member');

        $response = $this->withToken($this->login())
            ->postJson("/api/organizations/{$this->rival->id}/switch")
            ->assertOk();

        $claims = $this->claims($response->json('data.token'));

        $this->assertSame($this->rival->id, $claims['org_id'] ?? null);
        $this->assertSame('member', $claims['role'] ?? null);
    }

    /**
     * The new token replaces the old one. Left valid, the old one could be
     * switched again and again, each time for a fresh token, and outlive
     * the refresh window.
     */
    public function test_switching_revokes_the_old_token(): void
    {
        $this->join($this->acme);
        $this->join($this->rival, 'member');
        $old = $this->login();

        $new = $this->withToken($old)
            ->postJson("/api/organizations/{$this->rival->id}/switch")
            ->assertOk()
            ->json('data.token');

        $this->flushHeaders()->withToken($old)->getJson('/api/invoices')->assertUnauthorized();
        $this->flushHeaders()->withToken($old)->postJson("/api/organizations/{$this->acme->id}/switch")->assertUnauthorized();
        $this->flushHeaders()->withToken($new)->getJson('/api/invoices')->assertOk();
    }

    /**
     * A switch keeps the sign-in's time, so switching back and forth cannot
     * keep a session alive past the refresh window (jwt.refresh_ttl) the way
     * a fresh login would.
     */
    public function test_switching_cannot_outlive_the_window(): void
    {
        config(['jwt.refresh_ttl' => 180]);
        $this->join($this->acme);
        $this->join($this->rival, 'member');
        $signedInAt = now()->getTimestamp();
        $token = $this->login();

        // Switch every 50 minutes, inside each token's own lifetime.
        $windowEnd = now()->addMinutes((int) config('jwt.refresh_ttl'));
        $target = $this->rival;
        while (now()->addMinutes(50)->lt($windowEnd)) {
            $this->travel(50)->minutes();
            $response = $this->flushHeaders()->withToken($token)->postJson("/api/organizations/{$target->id}/switch")->assertOk();
            $token = $response->json('data.token');
            $this->assertSame($signedInAt, JWTAuth::setToken($token)->getPayload()->get('iat'));
            $target = $target->is($this->rival) ? $this->acme : $this->rival;
        }

        $this->travelTo($windowEnd->copy()->addMinute());

        $this->flushHeaders()->withToken($token)->postJson("/api/organizations/{$target->id}/switch")->assertUnauthorized();
        $this->flushHeaders()->withToken($token)->getJson('/api/invoices')->assertUnauthorized();
    }

    /**
     * The token has to actually scope what it reaches, not merely name an
     * organization.
     */
    public function test_scoped_token_reads_its_tenant(): void
    {
        $this->join($this->acme);

        $this->invoiceFor($this->acme);
        $this->invoiceFor($this->rival);

        $response = $this->withToken($this->login())
            ->getJson('/api/invoices')
            ->assertOk();

        $numbers = array_column($response->json('data') ?? [], 'invoice_number');

        $this->assertSame(['ACME-1'], $numbers);
    }

    /**
     * Membership is what switching checks. A user cannot name an organization
     * they do not belong to and receive a token for it.
     */
    public function test_switching_refuses_a_foreign_organization(): void
    {
        $this->join($this->acme);

        $this->withToken($this->login())
            ->postJson("/api/organizations/{$this->rival->id}/switch")
            ->assertNotFound();
    }

    private function join(Organization $organization, string $role = 'admin'): void
    {
        $this->user->organizations()->attach($organization->id, [
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function login(): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'member@example.test',
            'password' => 'password',
        ])->assertOk()->json('data.token.access_token');
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(string $token): array
    {
        [, $payload] = explode('.', $token);

        return (array) json_decode(
            (string) base64_decode(strtr($payload, '-_', '+/')),
            true
        );
    }

    private function invoiceFor(Organization $organization): void
    {
        Invoice::acrossTenants()->create([
            'org_id' => $organization->id,
            'invoice_number' => $organization->name === 'Acme' ? 'ACME-1' : 'RIVAL-1',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
        ]);
    }
}
