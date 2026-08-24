<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\PipelineResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * What ZATCA clears is what the invoice is.
 *
 * For a standard invoice the authority does not merely acknowledge the
 * document — it stamps its own copy and returns it, and that copy is the legal
 * invoice. FatooraResponse has parsed it into clearedInvoice since it was
 * written, and nothing outside a DTO unit test ever read the field, so the
 * platform archived and served the version it had submitted instead.
 *
 * A simplified invoice is reported rather than cleared. There is no returned
 * document, and what we signed is what stands.
 */
class ClearedDocumentTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const STAMPED = '<Invoice><ZatcaStamp>cleared</ZatcaStamp></Invoice>';

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
            // Submitter refuses to send for an organization that has not
            // finished onboarding, which is the state this test starts after.
            // zatca_onboarded is an accessor, not a column; this is the flag it
            // falls through to when there is no compliance profile.
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $credentials = $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
        );
    }

    public function test_cleared_document_is_kept(): void
    {
        $invoice = $this->submitted(base64_encode(self::STAMPED));

        $this->assertSame(
            self::STAMPED,
            $invoice->cleared_xml,
            'The document ZATCA cleared was not kept.'
        );
    }

    public function test_cleared_document_is_the_legal_one(): void
    {
        $invoice = $this->submitted(base64_encode(self::STAMPED));

        $this->assertNotNull($invoice->signed_xml, 'The submitted document should still be kept.');
        $this->assertNotSame($invoice->signed_xml, $invoice->cleared_xml);

        $this->assertSame(
            self::STAMPED,
            $invoice->legal_xml,
            'The invoice of record is still the pre-clearance document.'
        );
    }

    /**
     * Reporting acknowledges a document rather than returning one, so there is
     * nothing to prefer and what we signed remains the invoice.
     */
    public function test_reported_invoice_keeps_its_own(): void
    {
        $invoice = $this->submitted(null);

        $this->assertNull($invoice->cleared_xml);
        $this->assertSame($invoice->signed_xml, $invoice->legal_xml);
    }

    /**
     * ZATCA returns the document base64-encoded. Something that does not
     * decode is still the authority's answer and is worth more kept than
     * dropped.
     */
    public function test_undecodable_document_is_kept(): void
    {
        $invoice = $this->submitted('not base64 !!');

        $this->assertSame('not base64 !!', $invoice->cleared_xml);
    }

    public function test_pipeline_hands_back_the_cleared_one(): void
    {
        $invoice = $this->submitted(base64_encode(self::STAMPED));

        $payload = app(PipelineResult::class)
            ->build($invoice->fresh(), [], [], $invoice->zatca_response);

        $this->assertSame(self::STAMPED, $payload['signed_xml']);
        $this->assertSame(self::STAMPED, $payload['cleared_xml']);
    }

    private function submitted(?string $clearedInvoice): Invoice
    {
        $client = \Mockery::mock(FatooraClient::class);
        $client->shouldReceive('getEnvironment')->andReturn('sandbox');

        foreach (['clearInvoice', 'reportInvoice'] as $method) {
            $client->shouldReceive($method)->andReturn(new FatooraResponse(
                success: true,
                clearanceStatus: 'CLEARED',
                reportingStatus: null,
                validationStatus: 'PASS',
                clearedInvoice: $clearedInvoice,
                validationResults: [],
                warningMessages: [],
                errorMessages: [],
                rawResponse: null,
            ));
        }

        $this->app->instance(FatooraClient::class, $client);

        $invoice = $this->issued();

        app(Submitter::class)->submit($invoice, $this->organization);

        return $invoice->fresh();
    }

    private function issued(): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => 'INV-1',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '399999999900003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        app(Submitter::class)->generate($invoice, $this->organization);

        return $invoice->fresh(['lines']);
    }
}
