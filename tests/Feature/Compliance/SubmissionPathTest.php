<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\SubmissionTracker;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The whole synchronous path: sign the document, then submit it.
 *
 * Nothing had ever run it. SubmissionTracker::submit() refused every invoice as
 * a duplicate of itself — checkUuid() is Invoice::find() on the invoice's own
 * primary key — so no test could have reached ZATCA through it even if one had
 * tried, and the failure looked exactly like a legitimate duplicate.
 *
 * That makes this the check the product most needed and least had: the main
 * path, driven end to end, with only the authority itself doubled.
 *
 * The two halves are separate on purpose. Submitter::generate() signs and
 * issues; SubmissionTracker::submit() sends. verifyInvoiceState() enforces the
 * order, refusing an unsigned document rather than sending a null one.
 */
class SubmissionPathTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    /** @var array{xml: string|null, hash: string|null} */
    private array $sent = ['xml' => null, 'hash' => null];

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

        $client = \Mockery::mock(FatooraClient::class);
        foreach (['clearInvoice', 'reportInvoice'] as $method) {
            $client->shouldReceive($method)->andReturnUsing(function (string $xml, string $hash) {
                $this->sent = ['xml' => $xml, 'hash' => $hash];

                return new FatooraResponse(
                    success: true,
                    clearanceStatus: 'CLEARED',
                    reportingStatus: null,
                    validationStatus: 'PASS',
                    clearedInvoice: null,
                    validationResults: [],
                    warningMessages: [],
                    errorMessages: [],
                    rawResponse: null,
                );
            });
        }
        $this->app->instance(FatooraClient::class, $client);
    }

    public function test_a_signed_invoice_submits(): void
    {
        $invoice = $this->issued('INV-1');

        app(SubmissionTracker::class)->submit($invoice);

        $this->assertNotNull($this->sent['xml'], 'Nothing reached the authority.');
        $this->assertStringContainsString('<ds:Signature', $this->sent['xml']);
        $this->assertSame($invoice->fresh()->hash, $this->sent['hash']);
    }

    public function test_submission_is_recorded_as_cleared(): void
    {
        app(SubmissionTracker::class)->submit($this->issued('INV-1'));

        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::first());

        $this->assertNotNull($submission, 'No submission was recorded.');
        $this->assertSame('cleared', $submission->state);
        $this->assertSame('CLEARED', $submission->clearance_status);
    }

    /**
     * One outcome, one webhook, in one shape.
     *
     * SubmissionTracker raised no event at all, so a synchronous submission
     * produced none from the listener, while the pipeline announced its own
     * under the same event names with a different payload — total against
     * total_amount, type against invoice_type. A queued submission delivered
     * two webhooks for one outcome and an integrator could rely on neither.
     */
    public function test_submission_announces_once(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        Webhook::withoutTenantScope(fn () => Webhook::create([
            'org_id' => $this->organization->id,
            'url' => 'https://erp.test/hooks',
            'secret' => 'shhh',
            'events' => ['invoice.cleared'],
            'is_active' => true,
        ]));

        app(SubmissionTracker::class)->submit($this->issued('INV-1'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ($request->data()['event'] ?? null) === 'invoice.cleared'
            && ($request->data()['data']['total_amount'] ?? null) === '115.00');
    }

    /**
     * Signing has to happen first. An unsigned invoice would otherwise be sent
     * as a null document, which ZATCA answers with an error that says nothing
     * about the real cause.
     */
    public function test_an_unsigned_invoice_is_refused(): void
    {
        $this->expectExceptionMessage('Invoice must be signed before submission');

        app(SubmissionTracker::class)->submit($this->draft('INV-2'));
    }

    /**
     * Submitting twice returns the first result rather than sending again.
     * ZATCA rejects a document it has already cleared, and the idempotency
     * record is what keeps a retry from becoming that rejection.
     */
    public function test_resubmission_does_not_send_twice(): void
    {
        $invoice = $this->issued('INV-1');

        app(SubmissionTracker::class)->submit($invoice);
        $this->sent = ['xml' => null, 'hash' => null];

        app(SubmissionTracker::class)->submit($invoice);

        $this->assertNull($this->sent['xml'], 'The document was sent to the authority twice.');
    }

    private function issued(string $number): Invoice
    {
        $invoice = $this->draft($number);

        app(Submitter::class)->generate($invoice, $this->organization);

        return $invoice->fresh(['lines']);
    }

    private function draft(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
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
        ]));

        return $invoice->fresh(['lines']);
    }
}
