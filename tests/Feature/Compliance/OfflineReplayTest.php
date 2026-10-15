<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Models\OfflineItem;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\OfflineQueue;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Replaying the offline queue once connectivity returns.
 *
 * An item the authority accepted is finished. If recording that acceptance
 * fails locally, the item must not go back into the queue, because the next
 * run would send a document ZATCA already holds.
 */
class OfflineReplayTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    private Invoice $invoice;

    private OfflineItem $item;

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
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']
        );

        $this->invoice = $this->issued();

        $queued = app(OfflineQueue::class)->queue(
            $this->invoice,
            (string) $this->invoice->signed_xml,
            (string) $this->invoice->hash,
            (string) $this->invoice->qr_code,
        );

        $this->item = OfflineItem::withoutTenantScope(fn () => OfflineItem::find($queued['queue_id']));
    }

    public function test_accepted_item_marks_invoice_accepted(): void
    {
        $this->authorityAccepts();

        $this->replay();

        $this->assertSame(OfflineQueue::STATE_COMPLETED, $this->item->fresh()->state);
        $this->assertSame(InvoiceStatus::Accepted, $this->invoice->fresh()->status);
    }

    /**
     * The invoice write fails after acceptance. The item keeps its place out
     * of the queue, and a second run sends nothing.
     */
    public function test_unrecorded_acceptance_is_not_requeued(): void
    {
        $this->authorityAccepts();

        Invoice::updating(fn (Invoice $invoice) => $invoice->isDirty('status')
            ? throw new \RuntimeException('invoice store down')
            : null);

        $this->replay();
        $this->replay();

        $item = $this->item->fresh();

        $this->assertNotSame(OfflineQueue::STATE_PENDING, $item->state);
        $this->assertSame(0, $item->attempts);
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), '/invoices/')));
    }

    /**
     * A read timeout after the authority accepted does not re-file it.
     *
     * validateQueuedItem asked invoice_submissions whether this invoice had
     * already been filed, and the offline path writes no row there -
     * queueForOffline creates the queue item and the signed document and
     * stops. So the guard found nothing however many times the document had
     * been sent.
     *
     * A read timeout is indistinguishable from non-delivery, so the catch in
     * ProcessOfflineQueue calls markFailed with canRetry true and the item
     * goes back to pending. The next run then sent a document ZATCA had
     * already cleared. What this path does record is the verdict, on the
     * invoice itself, and the guard now reads that.
     */
    public function test_an_accepted_invoice_is_not_resent(): void
    {
        $this->authorityAccepts();

        // The verdict, as InvoiceVerdict::record would have written it.
        Invoice::withoutTenantScope(fn () => $this->invoice->update(['status' => InvoiceStatus::Accepted]));

        // And the item back in the queue, which is what the timeout left.
        OfflineItem::withoutTenantScope(fn () => $this->item->update(['state' => OfflineQueue::STATE_PENDING]));

        $this->replay();

        $this->assertCount(
            0,
            Http::recorded(fn (Request $request) => str_contains($request->url(), '/invoices/')),
            'a document the authority had already accepted was sent again'
        );
    }

    /**
     * A refusal is the authority's answer, and it is final.
     *
     * The drain treated every unsuccessful response alike: the item went back
     * to pending and the document was sent again until the attempts ran out.
     * The refusal was recorded nowhere, so the invoice stayed issued with its
     * submission in flight - and once the item gave up, the stale-submission
     * recovery saw no live queue item and filed the same refused document
     * again. A refusal is now recorded as one is on every other path.
     */
    public function test_a_rejection_is_final(): void
    {
        Http::fake(['*' => Http::response([
            'clearanceStatus' => 'NOT_CLEARED',
            'validationResults' => [
                'status' => 'ERROR',
                'errorMessages' => [['code' => 'BR-KSA-37', 'message' => 'The seller address building number must contain 4 digits.']],
            ],
        ], 400)]);

        $this->replay();
        $this->replay();

        $this->assertSame(InvoiceStatus::Rejected, $this->invoice->fresh()->status);
        $this->assertNotSame(OfflineQueue::STATE_PENDING, $this->item->fresh()->state);
        $this->assertCount(
            1,
            Http::recorded(fn (Request $request) => str_contains($request->url(), '/invoices/')),
            'a refused document was sent again'
        );
    }

    /**
     * A busy authority has not looked at the document, so it goes back in
     * the queue and the invoice says nothing about it yet.
     */
    public function test_a_busy_authority_is_retried(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Service Unavailable'], 503)]);

        $this->replay();

        $this->assertSame(OfflineQueue::STATE_PENDING, $this->item->fresh()->state);
        $this->assertSame(InvoiceStatus::Issued, $this->invoice->fresh()->status);
    }

    private function authorityAccepts(): void
    {
        Http::fake(['*' => Http::response([
            'clearanceStatus' => 'CLEARED',
            'validationResults' => ['status' => 'PASS'],
        ])]);
    }

    private function replay(): void
    {
        $this->artisan('fatoora:process-offline', [
            '--organization' => $this->organization->id,
            '--force' => true,
        ]);
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

        // The line the stored totals describe: an invoice with none breaks
        // BR-16 and is refused before issue.
        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'tax_category' => 'S',
            'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice, $this->organization);

        return $invoice->fresh(['lines']);
    }
}
