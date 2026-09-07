<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The partner API, driven the way an ERP drives it.
 *
 * SubmissionPathTest proves the services underneath: sign, then send. This
 * proves the four endpoints an integrator actually calls, over HTTP, with the
 * licence credentials and scopes they would hold — generate, validate, submit,
 * status — and none of them had a test.
 *
 * That matters because the layer is thin and thin is where a mistyped helper
 * lives. It is also the closest thing to a sandbox round trip that can run
 * without credentials from the Fatoora portal: everything here is ours except
 * the authority itself, which is doubled.
 */
class CompliancePipelineTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    /** @var array<string, string> */
    private array $headers;

    /** @var list<string> */
    private array $sentTo = [];

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

        // Submitter refuses an organization that has not finished onboarding,
        // and rightly so. This is the flag onboarding itself writes.
        $this->organization->forceFill([
            'compliance_profile' => ['zatca_onboarded' => true],
        ])->save();

        $credentials = $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
        );

        $issued = License::createWithCredentials([
            'org_id' => $this->organization->id,
            'organization_name' => 'Acme Trading',
            'contact_email' => 'erp@acme.test',
            'tier' => 'starter',
            'scopes' => ['invoice.read', 'invoice.submit', 'compliance.status'],
        ]);

        $this->headers = [
            'X-API-Key' => $issued['api_key'],
            'X-API-Secret' => $issued['api_secret'],
            'Accept' => 'application/json',
        ];

        $client = \Mockery::mock(FatooraClient::class);

        foreach (['clearInvoice', 'reportInvoice'] as $method) {
            $client->shouldReceive($method)->andReturnUsing(function () use ($method) {
                $this->sentTo[] = $method;

                return new FatooraResponse(
                    success: true,
                    clearanceStatus: $method === 'clearInvoice' ? 'CLEARED' : null,
                    reportingStatus: $method === 'reportInvoice' ? 'REPORTED' : null,
                    validationStatus: 'PASS',
                    clearedInvoice: null,
                    validationResults: [],
                    warningMessages: [],
                    errorMessages: [],
                    rawResponse: null,
                );
            });
        }

        $client->shouldReceive('getEnvironment')->andReturn('sandbox');

        $client->shouldReceive('validateInvoice')->andReturn(new FatooraResponse(
            success: true,
            clearanceStatus: null,
            reportingStatus: null,
            validationStatus: 'PASS',
            clearedInvoice: null,
            validationResults: [],
            warningMessages: [],
            errorMessages: [],
            rawResponse: null,
        ));

        $this->app->instance(FatooraClient::class, $client);
    }

    /**
     * Draft to cleared, through the endpoints rather than the services: the
     * document is signed, given a counter in the chain, and cleared — and a
     * standard invoice must be cleared rather than reported, which is the
     * distinction the endpoint decides on the caller's behalf.
     */
    public function test_a_standard_invoice_is_cleared(): void
    {
        $invoice = $this->draftInvoice('INV-PIPE-1');

        $this->postJson("/api/v1/compliance/generate/{$invoice->id}", [], $this->headers)
            ->assertOk();

        $issued = $invoice->fresh();

        $this->assertNotNull($issued->hash, 'Generating produced no hash, so nothing was signed.');
        $this->assertNotNull($issued->icv, 'Generating allocated no counter, so the document is not in the chain.');

        $this->postJson("/api/v1/compliance/submit/{$invoice->id}", [], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'CLEARED');

        $this->assertSame(['clearInvoice'], $this->sentTo,
            'A standard invoice must be cleared, not reported.');
    }

    /**
     * Status answers for a document that has been through the whole path.
     */
    public function test_status_answers(): void
    {
        $invoice = $this->draftInvoice('INV-PIPE-2');

        $this->postJson("/api/v1/compliance/generate/{$invoice->id}", [], $this->headers)->assertOk();
        $this->postJson("/api/v1/compliance/submit/{$invoice->id}", [], $this->headers)->assertOk();

        $this->getJson("/api/v1/compliance/status/{$invoice->id}", $this->headers)
            ->assertOk();
    }

    /**
     * Submitting before issuing must stop here rather than at the authority.
     * An unissued document has no hash and no counter, so sending it would put
     * a gap in the chain that cannot be closed afterwards.
     */
    public function test_a_draft_is_refused(): void
    {
        $invoice = $this->draftInvoice('INV-PIPE-3');

        $this->postJson("/api/v1/compliance/submit/{$invoice->id}", [], $this->headers)
            ->assertStatus(422);

        $this->assertSame([], $this->sentTo, 'An unissued document reached the authority.');
    }

    /**
     * A licence carries scopes, and holding one does not imply the others.
     * invoice.read alone must not reach an endpoint that signs and issues.
     */
    public function test_a_missing_scope_is_refused(): void
    {
        $issued = License::createWithCredentials([
            'org_id' => $this->organization->id,
            'organization_name' => 'Read Only',
            'contact_email' => 'ro@acme.test',
            'tier' => 'starter',
            'scopes' => ['invoice.read'],
        ]);

        $invoice = $this->draftInvoice('INV-PIPE-4');

        $this->postJson("/api/v1/compliance/generate/{$invoice->id}", [], [
            'X-API-Key' => $issued['api_key'],
            'X-API-Secret' => $issued['api_secret'],
            'Accept' => 'application/json',
        ])->assertForbidden();
    }

    private function draftInvoice(string $number): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '399999999900003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]))->fresh();
    }
}
