<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Each device files as itself.
 *
 * ZATCA has no platform login. It onboards every EGS unit separately and
 * issues it a binarySecurityToken and a secret, and authenticates each call
 * with that pair. Clearance and reporting were sent with one global
 * ZATCA_USERNAME and ZATCA_PASSWORD instead, for every tenant - so either
 * every filing failed authentication, or every tenant filed as whichever
 * device those belonged to.
 *
 * Asserted on the Authorization header actually sent.
 */
class DeviceCredentialTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private Organization $beta;

    /** @var array<string, array{privateKey: string, certificate: string}> device name => its keys */
    private array $devices = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = $this->onboarded('Acme Trading', '300000000000003', 'acme', 'acme-secret');
        $this->beta = $this->onboarded('Beta Industries', '310000000000003', 'beta', 'beta-secret');

        Http::fake([
            '*/invoices/reporting/*' => Http::response([
                'reportingStatus' => 'REPORTED',
                'validationResults' => ['status' => 'PASS'],
            ]),
            '*/compliance/invoices' => Http::response([
                'reportingStatus' => 'REPORTED',
                'validationResults' => ['status' => 'PASS'],
            ]),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_each_tenant_files_as_itself(): void
    {
        app(SubmissionTracker::class)->submit($this->issued($this->acme, 'ACME-1'));
        app(SubmissionTracker::class)->submit($this->issued($this->beta, 'BETA-1'));

        $this->assertFiledAs('acme', 'acme-secret');
        $this->assertFiledAs('beta', 'beta-secret');
    }

    /**
     * A branch is its own EGS unit, onboarded with its own certificate.
     */
    public function test_a_branch_files_as_itself(): void
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

        $jeddah = $this->device('jeddah');

        app(CredentialStore::class)->put($this->acme->id, $branch->id, CredentialStore::PCSID, [
            'privateKey' => $jeddah['privateKey'],
            'pcsid' => $jeddah['certificate'],
            'secret' => 'jeddah-secret',
        ]);

        app(SubmissionTracker::class)->submit($this->issued($this->acme, 'ACME-2', $branch->id));

        $this->assertFiledAs('jeddah', 'jeddah-secret');
    }

    /**
     * A device onboarded without its secret kept sends nothing at all,
     * rather than something unauthenticated or under another device's name.
     */
    public function test_no_secret_sends_nothing(): void
    {
        $gamma = $this->onboarded('Gamma Foods', '320000000000003', 'gamma', null);

        $invoice = $this->issued($gamma, 'GAMMA-1');

        app(SubmissionTracker::class)->submit($invoice);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/invoices/reporting/'));
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    /**
     * Validation goes to ZATCA's compliance path, as the device's compliance
     * CSID. It went to /invoices/compliance under the global login, which is
     * neither ZATCA's path nor its credential.
     */
    public function test_validation_uses_the_compliance_certificate(): void
    {
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::CCSID, [
            'ccsid' => 'acme-compliance-token',
            'secret' => 'acme-compliance-secret',
        ]);

        $invoice = $this->issued($this->acme, 'ACME-3');

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);

        $this->withToken($token)->postJson('/api/compliance/sa/validate/'.$invoice->id)->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/compliance/invoices')
            && $request->header('Authorization') === ['Basic '.base64_encode('acme-compliance-token:acme-compliance-secret')]);
    }

    /**
     * The token a device authenticates with is the certificate it signs with,
     * as ZATCA issues it, so each device needs a real one of its own.
     *
     * @return array{privateKey: string, certificate: string}
     */
    private function device(string $name): array
    {
        return $this->devices[$name] ??= $this->selfSignedCredentials();
    }

    private function assertFiledAs(string $device, string $secret): void
    {
        $token = $this->device($device)['certificate'];

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/invoices/reporting/')
            && $request->header('Authorization') === ['Basic '.base64_encode($token.':'.$secret)]);
    }

    private function onboarded(string $name, string $vat, string $device, ?string $secret): Organization
    {
        $keys = $this->device($device);

        $organization = Organization::create([
            'name' => $name,
            'country' => 'SA',
            'vat_number' => $vat,
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        app(CredentialStore::class)->put($organization->id, null, CredentialStore::PCSID, array_filter([
            'privateKey' => $keys['privateKey'],
            'pcsid' => $keys['certificate'],
            'secret' => $secret,
        ]));

        return $organization;
    }

    private function issued(Organization $organization, string $number, ?string $branchId = null): Invoice
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $organization->id,
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

        app(Submitter::class)->generate($invoice->fresh(['lines']), $organization);

        return $invoice->fresh(['lines']);
    }
}
