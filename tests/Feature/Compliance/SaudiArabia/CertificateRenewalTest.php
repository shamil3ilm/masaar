<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateIdentity;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A document is filed with the certificate that signed it.
 *
 * ZATCA authenticates each call with the device's CSID and its secret, and
 * the document carries the certificate it was stamped with. A renewal gives
 * the device a new one, and the store used to overwrite the old - so an
 * invoice signed before the renewal and still queued, retried or reported
 * late went out under the new CSID, and the old secret was gone.
 *
 * Asserted on the Authorization header actually sent, on every path that
 * files: the submission tracker, the queued job, the offline queue and a
 * direct submit.
 */
class CertificateRenewalTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    /** @var array{privateKey: string, certificate: string} */
    private array $original;

    /** @var array{privateKey: string, certificate: string} */
    private array $renewed;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        Queue::fake();

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

        $this->original = $this->selfSignedCredentials(serial: 1);
        $this->renewed = $this->selfSignedCredentials(serial: 2, days: 730);

        $this->store($this->original, 'original-secret');

        Http::fake([
            '*/invoices/clearance/*' => Http::response(['clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS']]),
            '*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']]),
            '*/production/csids' => Http::response([
                'binarySecurityToken' => $this->renewed['certificate'],
                'secret' => 'renewed-secret',
                'requestID' => 1234567891,
            ]),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_old_invoice_files_with_its_certificate(): void
    {
        $queued = $this->issued('OLD-1');

        $this->renew();

        app(SubmissionTracker::class)->submit($queued);

        $this->assertFiledWith($this->original['certificate'], 'original-secret');
        $this->assertNotFiledWith($this->renewed['certificate'], 'renewed-secret');
    }

    /**
     * The new certificate signs what is issued after the renewal, and files it.
     */
    public function test_new_invoice_uses_the_renewed_certificate(): void
    {
        $this->renew();

        $invoice = $this->issued('NEW-1');

        $this->assertTrue(
            CertificateIdentity::ofDocument($invoice->signed_xml)->sameAs(CertificateIdentity::of($this->renewed['certificate'])),
            'a document issued after the renewal was signed with the old certificate'
        );

        app(SubmissionTracker::class)->submit($invoice);

        $this->assertFiledWith($this->renewed['certificate'], 'renewed-secret');
    }

    /**
     * The queued job, which the recovery sweep dispatches again.
     */
    public function test_retried_job_files_with_signing_certificate(): void
    {
        $invoice = $this->issued('OLD-2');
        $submission = InvoiceSubmission::acrossTenants()->create([
            'invoice_id' => $invoice->id,
            'org_id' => $this->acme->id,
            'state' => 'queued',
            'submission_type' => 'reporting',
            'submission_mode' => 'async',
        ]);

        $this->renew();

        $this->app->call([new ProcessFatooraSubmission($submission), 'handle']);

        $this->assertFiledWith($this->original['certificate'], 'original-secret');
    }

    /**
     * A document queued during an outage, drained after the renewal.
     */
    public function test_offline_replay_files_with_signing_certificate(): void
    {
        $invoice = $this->issued('OLD-3');
        app(OfflineQueue::class)->queue($invoice, (string) $invoice->signed_xml, (string) $invoice->hash, (string) $invoice->qr_code);

        $this->renew();

        $this->artisan('fatoora:process-offline', ['--organization' => $this->acme->id, '--force' => true]);

        $this->assertFiledWith($this->original['certificate'], 'original-secret');
    }

    public function test_direct_submit_files_with_signing_certificate(): void
    {
        $invoice = $this->issued('OLD-4');

        $this->renew();

        app(Submitter::class)->submit($invoice, $this->acme);

        $this->assertFiledWith($this->original['certificate'], 'original-secret');
    }

    /**
     * Renewal adds a version and supersedes the old one. It replaces nothing.
     */
    public function test_renewal_supersedes_and_keeps_the_old(): void
    {
        $this->renew();

        $versions = app(CredentialStore::class)->versions($this->acme->id);

        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $this->assertSame([CertificateVersion::SUPERSEDED, CertificateVersion::ACTIVE], $versions->pluck('status')->all());
        $this->assertSame(CertificateIdentity::of($this->original['certificate'])->id, $versions[0]->certificate_id);
        $this->assertNotNull($versions[0]->valid_to, 'the validity window was not read off the certificate');

        // Encrypted as the active one is: nothing on disk reads in clear.
        foreach (Storage::disk('creds')->allFiles('zatca') as $path) {
            $this->assertStringNotContainsString('original-secret', (string) Storage::disk('creds')->get($path));
            $this->assertStringNotContainsString('PRIVATE KEY', (string) Storage::disk('creds')->get($path));
        }
    }

    /**
     * A credential stored before versions were kept is version 1, unchanged,
     * and a renewal over it keeps it.
     */
    public function test_existing_record_becomes_version_one(): void
    {
        $store = app(CredentialStore::class);
        $legacy = [
            'pcsid' => $this->original['certificate'],
            'secret' => 'original-secret',
            'privateKey' => $this->original['privateKey'],
            'requestId' => '1234567890',
        ];

        // The file as it was always written, and no version rows: the state
        // every device onboarded before this change is in.
        $store->put($this->acme->id, null, CredentialStore::PCSID, $legacy);
        CertificateVersion::acrossTenants()->delete();
        $this->assertSame(["zatca/{$this->acme->id}/pcsid.json"], Storage::disk('creds')->allFiles('zatca'));

        $versions = $store->versions($this->acme->id);

        $this->assertCount(1, $versions);
        $this->assertSame(1, $versions[0]->version);
        $this->assertSame(CertificateVersion::ACTIVE, $versions[0]->status);
        // Recorded with the environment that issued it, the deployment's.
        $this->assertSame([...$legacy, 'environment' => 'sandbox'], $store->get($this->acme->id, null, CredentialStore::PCSID));

        $this->renew();

        $this->assertSame([...$legacy, 'environment' => 'sandbox'], $store->record($this->acme->id, null, $versions[0]->certificate_id));
    }

    /**
     * A revoked certificate files nothing, and says why.
     */
    public function test_revoked_certificate_is_refused_clearly(): void
    {
        $invoice = $this->issued('OLD-5');
        $this->renew();

        app(CredentialStore::class)->revoke($this->acme->id, null, CertificateIdentity::of($this->original['certificate'])->id);

        try {
            app(CredentialStore::class)->forInvoice($this->acme->id, $invoice->device_id, null, $invoice->id, $invoice->signed_xml);
            $this->fail('a document signed with a revoked certificate was filed');
        } catch (FatooraException $e) {
            $this->assertSame(ErrorCode::CERT_REVOKED, $e->getErrorCode());
            $this->assertStringContainsString('revoked', $e->getMessage());
        }

        $this->assertNotFiledWith($this->renewed['certificate'], 'renewed-secret');
    }

    /**
     * Revoking the certificate in use stops signing with it.
     */
    public function test_revoking_the_active_stops_signing(): void
    {
        $store = app(CredentialStore::class);

        $store->revoke($this->acme->id, null, CertificateIdentity::of($this->original['certificate'])->id);

        $this->assertFalse($store->has($this->acme->id, null, CredentialStore::PCSID));
        $this->assertSame(CertificateVersion::REVOKED, $store->versions($this->acme->id)[0]->status);
    }

    /**
     * An organization renews by onboarding again: a new OTP buys a new
     * compliance CSID on a new key, and the production step then asks for a
     * new certificate. It used to see the old one held and stop, so an
     * organization could never renew.
     */
    public function test_organization_renews_by_onboarding_again(): void
    {
        $store = app(CredentialStore::class);
        $store->put($this->acme->id, null, CredentialStore::CCSID, [
            'ccsid' => base64_encode('THE-NEW-COMPLIANCE-CERTIFICATE'),
            // The checks passed under it (OnboardingRecord).
            'compliance' => ['passed' => true],
            'secret' => 'compliance-secret',
            'requestId' => '1234567890',
            'privateKey' => $this->renewed['privateKey'],
        ]);

        app(OrganizationOnboarding::class)->requestPcsid($this->acme);

        $this->assertSame($this->renewed['certificate'], $store->get($this->acme->id, null, CredentialStore::PCSID)['pcsid']);
        $this->assertSame(
            [CertificateVersion::SUPERSEDED, CertificateVersion::ACTIVE],
            $store->versions($this->acme->id)->pluck('status')->all()
        );
    }

    /**
     * The production step run again on the key its certificate was issued
     * for is a retry, not a renewal, and asks the authority for nothing.
     */
    public function test_retried_production_step_asks_for_nothing(): void
    {
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::CCSID, [
            'ccsid' => base64_encode('THE-COMPLIANCE-CERTIFICATE'),
            'secret' => 'compliance-secret',
            'requestId' => '1234567890',
            'privateKey' => $this->original['privateKey'],
        ]);
        app(OrganizationOnboarding::class)->requestPcsid($this->acme);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/production/csids'));
        $this->assertCount(1, app(CredentialStore::class)->versions($this->acme->id));
    }

    /**
     * A branch renews by being reset and onboarded again. The reset used to
     * delete its certificate, so what it had signed could not be filed at
     * all; it is now kept as a superseded version.
     */
    public function test_branch_reset_keeps_what_it_signed(): void
    {
        $branch = Branch::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'name' => 'Jeddah Branch',
            'device_serial' => 'EGS-JED-0001',
            'industry' => 'Retail',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]);
        $credentials = app(CredentialStore::class);
        $credentials->put($branch->org_id, $branch->id, CredentialStore::PCSID, ['privateKey' => $this->original['privateKey'], 'pcsid' => $this->original['certificate'], 'secret' => 'branch-secret']);

        $signed = $this->issued('JED-1', $branch->id);

        $credentials->forget($branch->org_id, $branch->id);
        $credentials->put($branch->org_id, $branch->id, CredentialStore::PCSID, ['privateKey' => $this->renewed['privateKey'], 'pcsid' => $this->renewed['certificate'], 'secret' => 'renewed-branch-secret']);

        app(SubmissionTracker::class)->submit($signed);

        $this->assertFiledWith($this->original['certificate'], 'branch-secret');
        $this->assertSame(
            [CertificateVersion::SUPERSEDED, CertificateVersion::ACTIVE],
            app(CredentialStore::class)->versions($this->acme->id, $branch->id)->pluck('status')->all()
        );
    }

    /**
     * The expiry check reads the certificate the device signs with now.
     */
    public function test_expiry_check_reads_the_renewed_certificate(): void
    {
        $this->renew();

        $this->artisan('fatoora:check-certificate', ['--organization' => $this->acme->id])
            ->expectsOutputToContain(now()->addDays(730)->format('Y-m-d'))
            ->assertSuccessful();
    }

    private function renew(): void
    {
        $this->store($this->renewed, 'renewed-secret');
    }

    /**
     * @param  array{privateKey: string, certificate: string}  $keys
     */
    private function store(array $keys, string $secret): void
    {
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'],
            'pcsid' => $keys['certificate'],
            'secret' => $secret,
        ]);
    }

    private function assertFiledWith(string $token, string $secret): void
    {
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/invoices/')
            && $request->header('Authorization') === ['Basic '.base64_encode($token.':'.$secret)]);
    }

    private function assertNotFiledWith(string $token, string $secret): void
    {
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/invoices/')
            && $request->header('Authorization') === ['Basic '.base64_encode($token.':'.$secret)]);
    }

    private function issued(string $number, ?string $branchId = null): Invoice
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'branch_id' => $branchId,
            'invoice_number' => $number,
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
            'description' => 'Widget',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);

        return $invoice->fresh(['lines']);
    }
}
