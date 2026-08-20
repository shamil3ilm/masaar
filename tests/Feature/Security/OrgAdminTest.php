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
 * Some actions belong to whoever runs the organization, not to everyone in it.
 *
 * The pivot has carried a role since the schema was written and one route ever
 * read it, so any active member could obtain the taxpayer's signing
 * credentials, reset an onboarding that had already completed, suspend a
 * branch, or delete a filed invoice.
 *
 * The line drawn here is irreversibility and reach. Credentials and lifecycle
 * are admin: they are hard or impossible to undo and they affect the whole
 * organization. Invoicing is not: creating, generating and submitting
 * documents is the job a member is there to do, and requiring an admin for it
 * would end with everyone being an admin.
 *
 * The role is read from the database rather than the token's claim. The claim
 * is fixed when the token is issued and a demotion has to take effect before
 * it expires — the same reason membership is re-checked in JwtGuard.
 */
class OrgAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_a_member_may_not_onboard(): void
    {
        $this->as('member')
            ->postJson('/api/compliance/onboarding/ccsid', [
                'otp' => '123456',
                'common_name' => 'EGS-1',
                'serial_number' => '1-Masaar|2-1.0|3-abc',
            ])
            ->assertForbidden();
    }

    public function test_member_cannot_delete_invoice(): void
    {
        $invoice = $this->invoice();

        $this->as('member')
            ->deleteJson("/api/invoices/{$invoice->id}")
            ->assertForbidden();

        $this->assertNotNull(
            Invoice::withoutTenantScope(fn () => Invoice::find($invoice->id)),
            'The invoice was deleted by a request that was refused.'
        );
    }

    public function test_member_cannot_delete_webhook(): void
    {
        $webhook = $this->webhook();

        $this->as('member')
            ->deleteJson("/api/webhooks/{$webhook->id}")
            ->assertForbidden();
    }

    /**
     * The work a member is there for stays theirs.
     */
    public function test_a_member_may_still_invoice(): void
    {
        $this->as('member')
            ->getJson('/api/invoices')
            ->assertOk();

        $this->as('member')
            ->postJson('/api/invoices', $this->invoicePayload())
            ->assertSuccessful();
    }

    public function test_an_admin_may_delete_an_invoice(): void
    {
        $invoice = $this->invoice();

        $this->as('admin')
            ->deleteJson("/api/invoices/{$invoice->id}")
            ->assertSuccessful();
    }

    /**
     * Demotion has to take effect before the token expires, so the role comes
     * from the database and not from the claim the token was issued with.
     */
    public function test_a_demoted_admin_loses_the_power(): void
    {
        $invoice = $this->invoice();

        $user = $this->member('admin');

        $token = $this->tokenFor($user);

        $user->organizations()->updateExistingPivot($this->organization->id, [
            'role' => 'member',
        ]);

        $this->withToken($token)
            ->deleteJson("/api/invoices/{$invoice->id}")
            ->assertForbidden();
    }

    /**
     * One user per role, made once — calling this twice in a test must not
     * try to register the same address again.
     *
     * @var array<string, string>
     */
    private array $tokens = [];

    private function as(string $role): self
    {
        $this->tokens[$role] ??= $this->tokenFor($this->member($role));

        return $this->withToken($this->tokens[$role]);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['email' => $role.'@masaar.test']);

        $user->organizations()->attach($this->organization->id, [
            'role' => $role,
            'status' => 'active',
        ]);

        return $user;
    }

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token.access_token');
    }

    private function invoice(): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
        ]));
    }

    private function webhook(): Webhook
    {
        return Webhook::withoutTenantScope(fn () => Webhook::create([
            'org_id' => $this->organization->id,
            'url' => 'https://example.test/hook',
            'events' => ['invoice.cleared'],
            'secret' => 'shh',
            'is_active' => true,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(): array
    {
        return [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer',
            'lines' => [[
                'description' => 'Item',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ];
    }
}
