<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\CsrData;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\CsidOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\CsrBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Pilot\SimulationPreflight;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * End-to-end verification of the ZATCA Simulation lifecycle:
 * From company onboarding in the UI to invoice issuance and clearance.
 */
class SimulationFullLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        config(['fatoora.environment' => 'simulation']);

        // Register organization with complete Saudi compliance details
        $this->organization = Organization::create([
            'name' => 'Apex Cloud Trading Co',
            'name_ar' => 'شركة قمة السحاب للتجارة',
            'country' => 'SA',
            'vat_number' => '310123456700003',
            'cr_number' => '1010123456',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'status' => Organization::STATUS_ACTIVE,
            'compliance_profile' => [
                'environment' => FatooraEnvironment::SIMULATION,
            ],
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Ahmad Admin',
            'email' => 'ahmad@apex.test',
        ]);

        $this->admin->organizations()->attach($this->organization->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * Complete simulation onboarding and invoice clearance journey.
     */
    public function test_simulation_onboarding_and_clearance_lifecycle(): void
    {
        $credentials = $this->selfSignedCredentials();
        $token = base64_encode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $credentials['certificate']));

        // Bind CsrBuilder to use the test keypair so XadesSigner verifies the private key matches
        $csrBuilder = new class($credentials) extends CsrBuilder
        {
            /** @param array{privateKey: string, certificate: string} $creds */
            public function __construct(private readonly array $creds) {}

            public function generate(CsrData $data, string $template = self::TEMPLATE_SIMULATION): array
            {
                return [
                    'csr' => $this->build($data, $this->creds['privateKey'], $template),
                    'privateKey' => $this->creds['privateKey'],
                ];
            }
        };
        $this->app->instance(CsrBuilder::class, $csrBuilder);
        $this->app->forgetInstance(OrganizationOnboarding::class);
        $this->app->forgetInstance(CsidOnboarding::class);

        // 1. Verify Simulation Preflight readiness for the organization
        $preflight = app(SimulationPreflight::class);
        $checks = $this->acrossTenants(fn () => $preflight->run($this->organization));

        $this->assertTrue(
            collect($checks)->firstWhere('key', 'vat')?->passed ?? false,
            'VAT number check failed in preflight.'
        );
        $this->assertTrue(
            collect($checks)->firstWhere('key', 'seller_id')?->passed ?? false,
            'Seller ID check failed in preflight.'
        );
        $this->assertTrue(
            collect($checks)->firstWhere('key', 'address')?->passed ?? false,
            'Address check failed in preflight.'
        );
        $this->assertTrue(
            collect($checks)->firstWhere('key', 'arabic_name')?->passed ?? false,
            'Arabic name check failed in preflight.'
        );

        // 2. Initial visit to ZATCA onboarding wizard in the portal
        $portal = $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->organization->id]);

        $response = $portal->get('/portal/zatca');
        $response->assertOk();
        $response->assertSee(__('Connect to ZATCA'));
        $response->assertSee('Simulation');
        $response->assertSee(__('1. Compliance certificate'));
        $response->assertSee(__('2. Compliance checks'));
        $response->assertSee(__('3. Production certificate'));

        // 3. Step 1: Request CCSID via OTP
        Http::fake([
            '*/compliance' => Http::response([
                'requestID' => '1234567890',
                'binarySecurityToken' => $token,
                'secret' => 'sim-ccsid-secret-xyz',
            ]),
            '*/compliance/invoices' => Http::response([
                'clearanceStatus' => 'CLEARED',
                'reportingStatus' => 'REPORTED',
                'validationResults' => [
                    'status' => 'PASS',
                    'infoMessages' => [],
                    'warningMessages' => [],
                    'errorMessages' => [],
                ],
            ]),
            '*/production/csids' => Http::response([
                'binarySecurityToken' => $token,
                'secret' => 'sim-pcsid-secret-abc',
            ]),
            '*/invoices/clearance/single' => Http::response([
                'clearanceStatus' => 'CLEARED',
                'clearedInvoice' => base64_encode('<Invoice>cleared-content</Invoice>'),
            ]),
        ]);

        $ccsidResponse = $portal->post('/portal/zatca/ccsid', [
            'otp' => '123456',
            'common_name' => 'EGS1-APEX',
            'organization_unit' => 'Main Branch',
            'industry' => 'Technology',
        ]);

        $ccsidResponse->assertSessionHas('success');

        // Verify CCSID stored in encrypted credential store
        $store = app(CredentialStore::class);
        $ccsidData = $store->get($this->organization->id, null, CredentialStore::CCSID);
        $this->assertNotNull($ccsidData);
        $this->assertSame('sim-ccsid-secret-xyz', $ccsidData['secret']);

        // 4. Step 2: Run Compliance Checks
        $checkResponse = $portal->post('/portal/zatca/check');
        $checkResponse->assertSessionHas('success');

        // Verify 6 compliance check invoices were submitted
        $submitted = Http::recorded(fn ($req) => str_ends_with($req->url(), '/compliance/invoices'))->values();
        $this->assertCount(6, $submitted, 'Expected exactly 6 compliance documents.');

        // Verify hash chaining began from genesis hash
        $firstPayload = json_decode((string) $submitted[0][0]->body(), true);
        $firstXml = base64_decode($firstPayload['invoice']);
        $this->assertStringContainsString(FatooraConfig::GENESIS_PIH, $firstXml);

        // 5. Inspect UI after compliance checks pass
        $responseAfterChecks = $portal->get('/portal/zatca');
        $responseAfterChecks->assertOk();
        $responseAfterChecks->assertSee(__('All six compliance documents passed. Next, get the production certificate.'));
        $responseAfterChecks->assertSee(__('Standard invoice'));
        $responseAfterChecks->assertSee(__('Simplified invoice'));

        // 6. Step 3: Request Production CSID in Simulation mode
        $pcsidResponse = $portal->post('/portal/zatca/production');
        $pcsidResponse->assertSessionHas('success');

        // Verify organization is fully onboarded
        $this->organization->refresh();
        $this->assertTrue((bool) ($this->organization->compliance_profile['zatca_onboarded'] ?? false));

        // Verify PCSID is stored
        $pcsidData = $store->get($this->organization->id, null, CredentialStore::PCSID);
        $this->assertNotNull($pcsidData);
        $this->assertSame('sim-pcsid-secret-abc', $pcsidData['secret']);

        // 7. Verify UI now shows connected status banner
        $responseConnected = $portal->get('/portal/zatca');
        $responseConnected->assertOk();
        $responseConnected->assertSee(__('Connected.'));
        $responseConnected->assertSee('Apex Cloud Trading Co');

        // 8. Create, sign and clear an invoice in Simulation mode
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->organization->id,
            'invoice_number' => 'INV-SIM-001',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Saudi Client Co',
            'buyer_vat_number' => '399999999900003',
            'subtotal' => '1000.00',
            'tax_amount' => '150.00',
            'total' => '1150.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Software Subscription',
            'quantity' => '1',
            'unit_price' => '1000.00',
            'tax_rate' => '15',
            'tax_amount' => '150.00',
            'tax_category' => 'S',
            'line_total' => '1150.00',
        ]);

        app(Submitter::class)->generate($invoice, $this->organization);
        $receipt = app(Submitter::class)->submit($invoice, $this->organization);

        $this->assertTrue($receipt->success);
        $this->assertSame('CLEARED', $receipt->clearanceStatus);

        // 9. Verify invoice renders in the portal with cleared status
        $invoiceShow = $portal->get('/portal/invoices/'.$invoice->id);
        $invoiceShow->assertOk();
        $invoiceShow->assertSee('INV-SIM-001');
    }
}
