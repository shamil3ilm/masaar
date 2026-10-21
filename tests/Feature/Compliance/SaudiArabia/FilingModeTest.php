<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Which documents wait for the authority, and which are queued.
 *
 * Nothing was ever queued. ProcessFatooraSubmission::dispatch() is reached
 * only when $async is true; the only producer of that option was
 * OfflineFallback reading $options['async'], and its one caller passed just
 * the idempotency key. So every submission ran inline in the request on every
 * surface, while three dedicated workers - numprocs=3 on
 * --queue=zatca-submissions - consumed an empty queue, and the job's $tries,
 * backoff() and failed() never ran, so a failed submission got no automatic
 * retry at all.
 *
 * Which one is correct is not a performance question. The E-Invoicing
 * Implementation Resolution answers it differently for the two document types,
 * and this is the distinction Invoice::requiresClearance already draws:
 *
 * Clause Second has the authority stamp a standard invoice and notify the
 * issuer "prior to sharing them with the customers". Until clearance returns
 * the seller may not hand the document over, and has nothing to hand over
 * either - cleared_xml is what the printable copy checks before it renders.
 * Queueing that would make every B2B invoice unshareable until a worker ran.
 *
 * Clause Third has the seller present the printed copy of a simplified
 * invoice immediately, and allows twenty-four hours to report it. The receipt
 * is complete when it is issued. That window is the latitude a queue needs.
 */
class FilingModeTest extends TestCase
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
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $credentials = $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret'],
        );

        // Connectivity makes a real outbound request otherwise. false keeps
        // the online branch, which is the one being distinguished here.
        $this->mock(Connectivity::class, function ($mock) {
            $mock->shouldReceive('shouldUseOfflineMode')->andReturn(false);
        });
    }

    public function test_a_simplified_invoice_is_queued(): void
    {
        Queue::fake();

        $invoice = $this->draft('POS-1', 'simplified');

        app(InvoiceIssuer::class)->issue($invoice, $this->organization, file: true);

        Queue::assertPushed(ProcessFatooraSubmission::class);

        $this->assertSame(
            'queued',
            InvoiceSubmission::withoutTenantScope(
                fn () => InvoiceSubmission::where('invoice_id', $invoice->id)->value('state')
            ),
            'a simplified invoice was filed inline instead of being queued'
        );
    }

    /**
     * The queued answer named /api/compliance/sa/status/{submission id},
     * which looks up an invoice id and answered 404, on the tenant surface
     * whichever surface the caller came by. Its outcome is on the invoice.
     */
    public function test_queued_outcome_names_no_dead_url(): void
    {
        Queue::fake();

        $outcome = app(InvoiceIssuer::class)->issue($this->draft('POS-3', 'simplified'), $this->organization, file: true);

        $this->assertSame('queued', $outcome->submission['status'] ?? null);
        $this->assertArrayNotHasKey('check_status_url', $outcome->submission);
    }

    /**
     * And nothing was sent from the request itself.
     */
    public function test_queueing_calls_nobody_inline(): void
    {
        Queue::fake();
        Http::fake();

        $invoice = $this->draft('POS-2', 'simplified');

        app(InvoiceIssuer::class)->issue($invoice, $this->organization, file: true);

        Http::assertNothingSent();
    }

    /**
     * A standard invoice waits, because the seller cannot share it until the
     * authority has stamped it.
     */
    public function test_a_standard_invoice_waits(): void
    {
        Queue::fake();

        Http::fake(['*' => Http::response([
            'clearanceStatus' => 'CLEARED',
            'clearedInvoice' => base64_encode('<Invoice/>'),
        ])]);

        $invoice = $this->draft('INV-1', 'standard');

        app(InvoiceIssuer::class)->issue($invoice, $this->organization, file: true);

        Queue::assertNotPushed(ProcessFatooraSubmission::class);

        $this->assertNotSame(
            'queued',
            InvoiceSubmission::withoutTenantScope(
                fn () => InvoiceSubmission::where('invoice_id', $invoice->id)->value('state')
            ),
            'a standard invoice was queued, so the seller has no cleared copy to give the buyer'
        );
    }

    private function draft(string $number, string $type): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => $number,
            'type' => $type,
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '399999999900003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }
}
