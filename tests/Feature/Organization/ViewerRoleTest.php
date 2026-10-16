<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Mail\InvitationMail;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A viewer - an accountant or auditor - sees the books and changes nothing.
 */
class ViewerRoleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $viewer;

    private Invoice $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->viewer = User::factory()->create();
        $this->viewer->organizations()->attach($this->acme->id, ['role' => 'viewer', 'status' => 'active']);

        $this->draft = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'INV-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }

    public function test_the_portal_is_read_only(): void
    {
        $portal = $this->actingAs($this->viewer)->withSession(['portal_org_id' => $this->acme->id]);

        $portal->get('/portal/invoices')->assertOk()->assertSee('INV-1')->assertDontSee('New invoice');
        $portal->get("/portal/invoices/{$this->draft->id}")->assertOk()->assertDontSee('Issue this invoice?');
        $portal->get('/portal/invoices/create')->assertForbidden();
        $portal->post("/portal/invoices/{$this->draft->id}/issue")->assertForbidden();
        $portal->post('/portal/invoices', ['type' => 'simplified'])->assertForbidden();
    }

    public function test_the_api_is_read_only(): void
    {
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'viewer'])->fromUser($this->viewer);

        $this->withToken($token)->getJson('/api/invoices')->assertOk();
        $this->withToken($token)->postJson('/api/invoices', ['invoice_number' => 'X'])->assertForbidden();
        $this->withToken($token)->postJson("/api/compliance/sa/submit/{$this->draft->id}")->assertForbidden();
    }

    /**
     * Read from the membership, not the token: a member made a viewer is
     * read-only at once, without signing in again.
     */
    public function test_a_demoted_member_reads_only(): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'member'])->fromUser($member);

        $member->organizations()->updateExistingPivot($this->acme->id, ['role' => 'viewer']);

        $this->withToken($token)->postJson('/api/invoices', ['invoice_number' => 'X'])->assertForbidden();
    }

    public function test_an_admin_invites_a_viewer(): void
    {
        Mail::fake();
        $admin = User::factory()->create();
        $admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)->withSession(['portal_org_id' => $this->acme->id])
            ->post('/portal/team/invitations', ['email' => 'accountant@firm.sa', 'role' => 'viewer'])
            ->assertSessionHas('success');

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->role === 'viewer');
    }
}
