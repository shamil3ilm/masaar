<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Who a user is, where they belong and what they did - on one screen.
 *
 * The console showed organizations, licences, webhooks and the queue, and no
 * user anywhere. Debugging "I cannot sign in" or "who filed this" meant a
 * database client: the pivot for memberships, audit_logs for sign-ins, and
 * invoice_submissions.created_by for what they sent.
 */
class UserScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $clerk;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_platform_admin' => true]);

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);

        $this->clerk = User::factory()->create(['name' => 'Layla Clerk', 'email' => 'layla@acme.test']);
        $this->clerk->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_the_list_finds_a_user(): void
    {
        User::factory()->create(['name' => 'Omar Other', 'email' => 'omar@beta.test']);

        $this->screen('/admin/users?q=layla')
            ->assertOk()
            ->assertSee('layla@acme.test')
            ->assertDontSee('omar@beta.test');
    }

    public function test_the_list_shows_last_sign_in(): void
    {
        $this->signIn('security.login.succeeded', now()->subHours(3));

        $this->screen('/admin/users')->assertOk()->assertSee('3 hours ago');
    }

    public function test_the_detail_shows_everything_recorded(): void
    {
        $this->signIn('security.login.succeeded', now()->subDay(), '198.51.100.20');
        $this->signIn('security.login.failed', now()->subDays(2), '203.0.113.66');

        AuditLog::create([
            'org_id' => $this->acme->id,
            'user_id' => $this->clerk->id,
            'action' => 'Invoice.created',
            'entity_type' => 'Invoice',
            'ip_address' => '198.51.100.20',
        ]);

        $invoice = Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-77',
            'type' => 'simplified',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        (new InvoiceSubmission)->forceFill([
            'invoice_id' => $invoice->id,
            'org_id' => $this->acme->id,
            'state' => 'rejected',
            'submission_type' => 'reporting',
            'submission_mode' => 'sync',
            'created_by' => $this->clerk->id,
        ])->save();

        $this->screen('/admin/users/'.$this->clerk->id)
            ->assertOk()
            ->assertSee('Layla Clerk')
            ->assertSee('Acme Trading')
            ->assertSee('203.0.113.66')
            ->assertSee('Invoice.created')
            ->assertSee('ACME-77');
    }

    /**
     * The screen is for debugging, and none of these help with that.
     */
    public function test_no_secret_reaches_the_page(): void
    {
        $this->clerk->forceFill(['remember_token' => 'remember-me-abcdef0123'])->save();

        $html = $this->screen('/admin/users/'.$this->clerk->id)->assertOk()->getContent();

        $this->assertStringNotContainsString((string) $this->clerk->fresh()->password, (string) $html);
        $this->assertStringNotContainsString('remember-me-abcdef0123', (string) $html);
    }

    public function test_an_organization_lists_its_members(): void
    {
        $this->screen('/admin/organizations/'.$this->acme->id)
            ->assertOk()
            ->assertSee('layla@acme.test')
            ->assertSee(route('admin.users.show', $this->clerk->id), false);
    }

    /** Signed up with the VAT number in its profile, not the old column. */
    public function test_the_vat_number_is_shown(): void
    {
        $this->acme->forceFill(['compliance_profile' => ['vat_number' => '399999999900003']])->save();

        $this->screen('/admin/organizations/'.$this->acme->id)->assertOk()->assertSee('399999999900003');
        $this->screen('/admin/organizations')->assertOk()->assertSee('399999999900003');
    }

    /** The list read "810.9133753066 days": Carbon 3 gives a fraction of a day. */
    public function test_expiry_is_in_whole_days(): void
    {
        $this->mock(CredentialStore::class)->shouldReceive('certificate')->andReturn('pem');
        $this->mock(CertificateService::class)->shouldReceive('details')
            ->andReturn(['serial_number' => '1', 'valid_from' => now()->toDateTimeString(), 'valid_to' => now()->addDays(810)->addHours(22)->toDateTimeString(), 'status' => 'healthy']);

        $page = (string) $this->screen('/admin/organizations')->assertOk()->getContent();

        $this->assertStringContainsString(__(':days days', ['days' => 810]), $page);
        $this->assertDoesNotMatchRegularExpression('/810\.\d+/', $page);
    }

    private function signIn(string $action, \DateTimeInterface $at, string $ip = '198.51.100.20'): void
    {
        // Written at its time: an audit entry cannot be changed afterwards.
        (new AuditLog)->forceFill([
            'user_id' => $action === 'security.login.succeeded' ? $this->clerk->id : null,
            'action' => $action,
            'entity_type' => 'User',
            'entity_id' => $this->clerk->id,
            'ip_address' => $ip,
            'metadata' => ['surface' => 'portal'],
            'created_at' => $at,
        ])->save();
    }

    private function screen(string $uri): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->admin)->get($uri));
    }
}
