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
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
        );

        Http::fake(['*' => Http::response([
            'clearanceStatus' => 'CLEARED',
            'validationResults' => ['status' => 'PASS'],
        ])]);

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

        app(Submitter::class)->generate($invoice, $this->organization);

        return $invoice->fresh(['lines']);
    }
}
