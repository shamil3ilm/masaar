<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\OfflineItem;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\OfflineQueue;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ZATCA requires a taxpayer to keep issuing while the authority is unreachable
 * and to report afterwards, so invoices are signed at issue time and held here.
 * The queue is therefore only useful if it drains.
 *
 * It could not. validateQueuedItem() ran before every item and its first query
 * filtered invoice_submissions on a 'status' column that does not exist, so it
 * raised SQLSTATE[42S22] rather than returning a verdict. Past that, it asked a
 * certificate_lineage table that nothing ever wrote whether the organization
 * had a certificate, and concluded it did not.
 *
 * The queries now go through the models, so the tenant scope applies to work
 * that runs in a queue worker rather than depending on each method remembering
 * a where('org_id').
 */
class OfflineQueueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    private OfflineQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $this->queue = app(OfflineQueue::class);
    }

    public function test_queueing_stores_the_signed_document(): void
    {
        $invoice = $this->invoice($this->acme);

        $result = $this->queue->queue($invoice, '<Invoice/>', 'HASH', 'QR');

        $this->assertTrue($result['queued']);

        $item = OfflineItem::find($result['queue_id']);

        $this->assertNotNull($item);
        $this->assertSame($this->acme->id, $item->org_id);
        $this->assertSame(OfflineItem::PENDING, $item->state);
    }

    /**
     * A worker draining one organization must not be handed another's work.
     */
    public function test_queue_is_scoped_to_its_tenant(): void
    {
        $this->queue->queue($this->invoice($this->acme), '<Invoice/>', 'HASH', 'QR');
        $this->queue->queue($this->invoice($this->rival), '<Invoice/>', 'HASH', 'QR');

        $seen = app(TenantResolver::class)->runAs(
            $this->acme->id,
            fn () => OfflineItem::query()->get()
        );

        $this->assertCount(1, $seen);
        $this->assertSame($this->acme->id, $seen->first()->org_id);
    }

    public function test_item_validates_with_a_certificate(): void
    {
        $item = $this->queuedItem($this->acme);
        $this->storeCertificate($this->acme);

        $verdict = $this->queue->validateQueuedItem($item);

        $this->assertTrue($verdict['valid'], $verdict['reason'] ?? '');
        $this->assertSame('process', $verdict['action']);
    }

    public function test_item_refused_without_certificate(): void
    {
        $verdict = $this->queue->validateQueuedItem($this->queuedItem($this->acme));

        $this->assertFalse($verdict['valid']);
        $this->assertSame('resign', $verdict['action']);
    }

    /**
     * Replaying the queue must not file an invoice ZATCA already accepted.
     */
    public function test_an_already_cleared_invoice_is_skipped(): void
    {
        $this->storeCertificate($this->acme);
        $item = $this->queuedItem($this->acme);

        InvoiceSubmission::create([
            'org_id' => $this->acme->id,
            'invoice_id' => $item->invoice_id,
            'state' => 'cleared',
        ]);

        $verdict = $this->queue->validateQueuedItem($item);

        $this->assertFalse($verdict['valid']);
        $this->assertSame('skip', $verdict['action']);
    }

    public function test_failing_an_item_schedules_a_retry(): void
    {
        $item = $this->queuedItem($this->acme);

        $this->queue->markFailed($item->id, 'ZATCA unreachable');

        $item->refresh();

        $this->assertSame(OfflineItem::PENDING, $item->state);
        $this->assertSame(1, $item->attempts);
        $this->assertSame('ZATCA unreachable', $item->last_error);
    }

    public function test_spent_retries_fail_permanently(): void
    {
        $item = $this->queuedItem($this->acme);
        $item->update(['attempts' => 3, 'max_attempts' => 3]);

        $this->queue->markFailed($item->id, 'Rejected outright', false);

        $this->assertSame(OfflineItem::FAILED, $item->refresh()->state);
    }

    /**
     * The response is stored through the model's array cast rather than being
     * encoded by hand, so it reads back as an array.
     */
    public function test_completion_records_the_authority_response(): void
    {
        $item = $this->queuedItem($this->acme);

        $this->queue->markCompleted($item->id, ['invoiceUuid' => 'abc-123']);

        $item->refresh();

        $this->assertSame(OfflineItem::COMPLETED, $item->state);
        $this->assertSame(['invoiceUuid' => 'abc-123'], $item->zatca_response);
    }

    private function invoice(Organization $org): Invoice
    {
        return Invoice::create([
            'org_id' => $org->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
        ]);
    }

    private function queuedItem(Organization $org): OfflineItem
    {
        $result = $this->queue->queue($this->invoice($org), '<Invoice/>', 'HASH', 'QR');

        return OfflineItem::findOrFail($result['queue_id']);
    }

    private function storeCertificate(Organization $org): void
    {
        app(CredentialStore::class)->put($org->id, null, CredentialStore::PCSID, [
            'privateKey' => 'unused-here',
            'pcsid' => (string) file_get_contents(base_path('tests/Fixtures/Certificates/good.pem')),
        ]);
    }
}
