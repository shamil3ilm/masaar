<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\CertificateRevokedMail;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateRevocationActions;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Models\ApprovalRequest;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\RevocationAuthority;
use Tests\TestCase;

/**
 * Finding revoked certificates, and what follows.
 *
 * The CA publishes a revocation in its CRL and the EGS "reads periodically
 * from CRL to verify which Identifiers are still active" (Security Features
 * Implementation Standard v1.2, section 2.1.3). fatoora:check-revocation is
 * that read, for every version a device signs or still files with.
 */
class RevocationSweepTest extends TestCase
{
    use RefreshDatabase;
    use RevocationAuthority;

    private string $served = '';

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds', 'security.revocation_hosts' => ['crl.authority.test']]);
        Mail::fake();

        Http::fake([self::CRL_URL => fn () => Http::response($this->served), '*' => Http::response(['ok' => true])]);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $this->admin = User::factory()->create(['locale' => 'ar']);
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_revoked_active_version_stops_signing(): void
    {
        $this->store($this->issuedDevice(4096));
        $this->served = $this->revocationList([4096]);

        $this->artisan('fatoora:check-revocation')->assertSuccessful();

        $this->assertSame(CertificateVersion::REVOKED, $this->versions()[0]->status);
        $this->assertNull($this->versions()[0]->revoked_by);
        $this->assertNotNull($this->versions()[0]->revoked_reason);
        $this->assertFalse(app(CredentialStore::class)->has($this->acme->id, null, CredentialStore::PCSID));
        Mail::assertSent(CertificateRevokedMail::class, fn (CertificateRevokedMail $mail): bool => $mail->hasTo($this->admin) && $mail->stoppedSigning);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'zatca.certificate.revoked')->exists());
    }

    /**
     * Signing then refuses with the reason, rather than as a device that was
     * never onboarded, or by signing nothing.
     */
    public function test_revoked_device_explains_its_refusal(): void
    {
        $this->store($this->issuedDevice(4096));
        $this->served = $this->revocationList([4096]);
        $this->artisan('fatoora:check-revocation');

        try {
            app(Submitter::class)->generate($this->draft(), $this->acme);
            $this->fail('a device whose certificate was revoked signed an invoice');
        } catch (FatooraException $e) {
            $this->assertSame(ErrorCode::CERT_REVOKED, $e->getErrorCode());
            $this->assertStringContainsString('revoked', $e->getMessage());
        }
    }

    /**
     * A branch whose certificate was revoked does not sign as head office.
     */
    public function test_revoked_branch_never_signs_as_office(): void
    {
        $this->store($this->issuedDevice(4097));
        $branch = Branch::acrossTenants()->create([
            'org_id' => $this->acme->id, 'name' => 'Jeddah', 'device_serial' => 'EGS-JED', 'industry' => 'Retail',
            'street' => 'Corniche Road', 'building_number' => '4321', 'district' => 'Al Hamra', 'city' => 'Jeddah', 'postal_code' => '23234',
        ]);
        $this->store($this->issuedDevice(4096), $branch->id);
        $this->served = $this->revocationList([4096]);

        $this->artisan('fatoora:check-revocation');

        try {
            app(Submitter::class)->generate($this->draft($branch->id), $this->acme);
            $this->fail('a branch whose certificate was revoked signed as head office');
        } catch (FatooraException $e) {
            $this->assertSame(ErrorCode::CERT_REVOKED, $e->getErrorCode());
        }
    }

    /**
     * A portal renewal revokes the old certificate (section 2.1.2). Found
     * revoked, the superseded version files nothing more; the active one
     * signs on.
     */
    public function test_revoked_superseded_version_leaves_signing(): void
    {
        $this->store($this->issuedDevice(4096));
        $this->store($this->issuedDevice(4097));
        $this->served = $this->revocationList([4096]);

        $this->artisan('fatoora:check-revocation');

        $this->assertSame([CertificateVersion::REVOKED, CertificateVersion::ACTIVE], $this->versions()->pluck('status')->all());
        Mail::assertSent(CertificateRevokedMail::class, fn (CertificateRevokedMail $mail): bool => ! $mail->stoppedSigning);
        $this->assertNotNull(app(Submitter::class)->generate($this->draft(), $this->acme)['hash'] ?? null);
    }

    /**
     * Each administrator reads it in their own language.
     */
    public function test_the_notice_reads_in_arabic(): void
    {
        $mail = (new CertificateRevokedMail('Acme Trading', 1, '1000', true, app(ThemeService::class)->forScope($this->acme->id)))->locale('ar');

        $this->assertStringContainsString('أُلغيت شهادتك', $mail->render());
    }

    public function test_admin_marks_a_version_revoked(): void
    {
        $this->store($this->issuedDevice(4097));
        $version = $this->versions()[0];

        $operator = User::factory()->platformAdmin()->create();

        $this->actingAs($operator)
            ->withSession(['_token' => 'token'])
            ->post("/admin/certificates/{$version->id}/revoke", ['reason' => 'ZATCA confirmed the key was exposed', '_token' => 'token'])
            ->assertRedirect(route('admin.certificates'))
            ->assertSessionHas('success');

        $this->assertSame(CertificateVersion::REVOKED, $version->fresh()->status);
        $this->assertSame($operator->id, $version->fresh()->revoked_by);
        $this->assertSame('ZATCA confirmed the key was exposed', $version->fresh()->revoked_reason);
        $this->assertFalse(app(CredentialStore::class)->has($this->acme->id, null, CredentialStore::PCSID));
    }

    /**
     * Marking a certificate revoked stops a till signing, so it ships held
     * for a second admin, like the changes to admin access. Approved, it
     * runs as the admin who asked.
     */
    public function test_revocation_ships_held_for_approval(): void
    {
        $this->assertTrue(app(ApprovalPolicies::class)->get(CertificateRevocationActions::REVOKE)->enabled);

        $this->store($this->issuedDevice(4097));
        $version = $this->versions()[0];
        $owner = $this->operator('owner', 'owner@example.test');
        $operations = $this->operator('operations', 'operations@example.test');

        $this->actingAs($owner)
            ->withSession(['_token' => 'token'])
            ->post("/admin/certificates/{$version->id}/revoke", ['reason' => 'ZATCA confirmed the key was exposed', '_token' => 'token'])
            ->assertSessionHas('success', 'Sent for approval. Nothing changes until it is approved.');

        $request = ApprovalRequest::query()->where('action', CertificateRevocationActions::REVOKE)->sole();
        $this->assertSame(ApprovalRequest::PENDING, $request->status);
        $this->assertSame(CertificateVersion::ACTIVE, $version->fresh()->status);
        $this->assertTrue(app(CredentialStore::class)->has($this->acme->id, null, CredentialStore::PCSID));

        $this->actingAs($operations)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('success');

        $this->assertSame(CertificateVersion::REVOKED, $version->fresh()->status);
        $this->assertSame($owner->id, $version->fresh()->revoked_by);
        $this->assertSame(ApprovalRequest::EXECUTED, $request->fresh()->status);
        $this->assertFalse(app(CredentialStore::class)->has($this->acme->id, null, CredentialStore::PCSID));
    }

    public function test_revocation_policy_off_runs_at_once(): void
    {
        $policies = app(ApprovalPolicies::class);
        $owner = $this->operator('owner', 'owner@example.test');
        $this->operator('operations', 'operations@example.test');
        $policies->save(CertificateRevocationActions::REVOKE, $policies->clean(CertificateRevocationActions::REVOKE, [
            'approvals_required' => 1, 'approver_roles' => ['owner', 'operations'], 'expires_after_hours' => 24,
        ]), (string) $owner->id);
        $this->store($this->issuedDevice(4097));
        $version = $this->versions()[0];

        $this->actingAs($owner)
            ->withSession(['_token' => 'token'])
            ->post("/admin/certificates/{$version->id}/revoke", ['reason' => 'ZATCA confirmed the key was exposed', '_token' => 'token'])
            ->assertRedirect(route('admin.certificates'));

        $this->assertSame(CertificateVersion::REVOKED, $version->fresh()->status);
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_revoked_version_cannot_be_asked_again(): void
    {
        $this->store($this->issuedDevice(4097));
        $version = $this->versions()[0];
        app(CredentialStore::class)->revoke($this->acme->id, null, $version->certificate_id);

        $this->actingAs($this->operator('owner', 'owner@example.test'))
            ->withSession(['_token' => 'token'])
            ->post("/admin/certificates/{$version->id}/revoke", ['reason' => 'ZATCA confirmed the key was exposed', '_token' => 'token'])
            ->assertSessionHas('error', 'This certificate is already recorded as revoked.');

        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_admin_page_lists_certificates(): void
    {
        $this->store($this->issuedDevice(4097));

        $this->actingAs(User::factory()->platformAdmin()->create())->get('/admin/certificates')
            ->assertOk()
            ->assertSee('Acme Trading')
            ->assertSee('Mark revoked');
    }

    private function operator(string $role, string $email): User
    {
        return User::factory()->create([
            'is_platform_admin' => true, 'platform_role' => $role, 'email' => $email,
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * @param  array{privateKey: string, certificate: string}  $device
     */
    private function store(array $device, ?string $branchId = null): void
    {
        app(CredentialStore::class)->put($this->acme->id, $branchId, CredentialStore::PCSID, [
            'privateKey' => $device['privateKey'],
            'pcsid' => $device['certificate'],
            'secret' => 'device-secret',
        ]);
    }

    /**
     * @return Collection<int, CertificateVersion>
     */
    private function versions(): Collection
    {
        return CertificateVersion::acrossTenants()
            ->where('device_id', $this->acme->id)->orderBy('version')->get();
    }

    private function draft(?string $branchId = null): Invoice
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'branch_id' => $branchId,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }
}
