<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A branch that is not ready must not file.
 *
 * Suspending one is how a taxpayer stops a location invoicing — a closed shop,
 * a device that was lost, a registration under review. If the submission path
 * ignored it the suspension would be advisory: the branch would keep filing
 * under the organization's name and the authority would keep accepting.
 *
 * Readiness is three things at once, and each can fail on its own: the
 * onboarding has to have completed, the branch has to be active, and its
 * certificate has to still be valid. An expired certificate matters most
 * because nobody performs it — it arrives on its own.
 *
 * The check sits on submit() rather than generate(). Building and signing a
 * document locally changes nothing outside the platform; sending it to the
 * authority is the act a suspension is meant to prevent.
 */
class BranchReadinessTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $credentials = $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
        );

        $client = Mockery::mock(FatooraClient::class);

        // submit() checks the environment before anything else, so the double
        // has to answer it or every case fails before reaching the branch.
        $client->shouldReceive('getEnvironment')->andReturn('sandbox');

        foreach (['clearInvoice', 'reportInvoice'] as $method) {
            $client->shouldReceive($method)->andReturn(new FatooraResponse(
                success: true,
                clearanceStatus: 'CLEARED',
                reportingStatus: null,
                validationStatus: 'PASS',
                clearedInvoice: null,
                validationResults: [],
                warningMessages: [],
                errorMessages: [],
                rawResponse: null,
            ));
        }

        $this->app->instance(FatooraClient::class, $client);

        // An organization counts as onboarded when it has an active branch, so
        // a taxpayer whose only branch is suspended is refused for the
        // organization rather than the branch. Giving it a second, working
        // location makes this about the branch under test — and is the case
        // that actually occurs: several shops, one of them closed.
        $this->branch(Branch::STATUS_ACTIVE);
    }

    public function test_an_active_branch_files(): void
    {
        $branch = $this->branch(Branch::STATUS_ACTIVE);

        $response = app(Submitter::class)->submit($this->invoiceFor($branch), $this->organization);

        $this->assertTrue($response->success);
    }

    public function test_a_suspended_branch_is_refused(): void
    {
        $this->assertRefused($this->branch(Branch::STATUS_SUSPENDED));
    }

    /**
     * A branch part-way through onboarding holds no production credential, so
     * anything it signed would be stamped with the wrong certificate.
     */
    public function test_a_pending_branch_is_refused(): void
    {
        $this->assertRefused($this->branch(Branch::STATUS_PENDING));
    }

    /**
     * Deactivating without changing the onboarding status is the other way a
     * branch is stopped, and it has to count for as much.
     */
    public function test_an_inactive_branch_is_refused(): void
    {
        $this->assertRefused($this->branch(Branch::STATUS_ACTIVE, active: false));
    }

    /**
     * Nobody decides this one — it happens by the calendar.
     */
    public function test_an_expired_certificate_is_refused(): void
    {
        $this->assertRefused(
            $this->branch(Branch::STATUS_ACTIVE, expiresAt: now()->subDay())
        );
    }

    private function assertRefused(Branch $branch): void
    {
        $invoice = $this->invoiceFor($branch);

        try {
            app(Submitter::class)->submit($invoice, $this->organization);
        } catch (FatooraException $e) {
            $this->assertStringContainsString('Branch', $e->getMessage());

            return;
        }

        $this->fail('A branch that is not ready produced a signed document.');
    }

    private function branch(
        string $status,
        bool $active = true,
        ?\DateTimeInterface $expiresAt = null,
    ): Branch {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->organization->id,
            'name' => 'Jeddah Branch',
            'device_serial' => 'EGS-'.uniqid(),
            'industry' => 'Retail',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
            'onboarding_status' => $status,
            'is_active' => $active,
            'cert_expires_at' => $expiresAt,
        ]));
    }

    private function invoiceFor(Branch $branch): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'branch_id' => $branch->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer Co',
            'buyer_vat_number' => '311111111111113',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }
}
