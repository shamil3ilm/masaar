<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\OfflineItem;
use App\Domains\Compliance\Fatoora\Services\OfflineQueue;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * An invoice filed offline is filed once.
 *
 * Queueing an invoice offline also writes it a submission row, queued. The
 * offline drain files it, and recorded the answer on the invoice and on the
 * queue item - never on that row. So the row sat queued for ever, and
 * fatoora:recover-submissions, which resends a queued row that has gone
 * stale, would have filed the invoice a second time fifteen minutes after
 * the drain filed it - or alongside the drain, while the invoice was still
 * waiting offline. For a standard invoice that is a second clearance.
 *
 * The drain owns an invoice while it holds a queue item for it; it records
 * the answer on the submission row as every other path does; and nothing
 * sends an invoice the authority has already accepted.
 */
class OfflineOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fatoora.recovery.stale_minutes' => 15]);
        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
    }

    public function test_recovery_leaves_offline_invoices_alone(): void
    {
        Queue::fake();

        [$invoice] = $this->queuedOffline();

        $this->artisan('fatoora:recover-submissions')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_drain_closes_the_submission(): void
    {
        [$invoice, $submission, $item] = $this->queuedOffline();

        app(OfflineQueue::class)->complete($item->id, $invoice, $this->reported());

        $this->assertSame('reported', $submission->fresh()->state);
        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
    }

    /**
     * And whatever else goes wrong, an accepted invoice is not sent again.
     */
    public function test_an_accepted_invoice_is_not_resent(): void
    {
        Http::fake();

        [$invoice, $submission, $item] = $this->queuedOffline();
        $item->update(['state' => 'completed']);
        $invoice->update(['status' => InvoiceStatus::Accepted]);

        $this->app->call([new ProcessFatooraSubmission($submission), 'handle']);

        Http::assertNothingSent();
    }

    /**
     * @return array{0: Invoice, 1: InvoiceSubmission, 2: OfflineItem}
     */
    private function queuedOffline(): array
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-'.uniqid(),
            'type' => 'simplified',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            'icv' => 1,
            'hash' => base64_encode(random_bytes(32)),
        ]));

        $submission = (new InvoiceSubmission)->forceFill([
            'invoice_id' => $invoice->id,
            'org_id' => $this->acme->id,
            'state' => 'queued',
            'submission_type' => 'reporting',
            'submission_mode' => 'async',
            'state_changed_at' => now()->subHour(),
        ]);
        $submission->save();

        $item = OfflineItem::withoutTenantScope(fn () => OfflineItem::create([
            'org_id' => $this->acme->id,
            'invoice_id' => $invoice->id,
            'signed_xml' => '<Invoice/>',
            'invoice_hash' => (string) $invoice->hash,
            'qr_code' => base64_encode('qr'),
            'state' => 'pending',
            'queued_at' => now()->subHour(),
        ]));

        return [$invoice, $submission, $item];
    }

    private function reported(): FatooraResponse
    {
        return new FatooraResponse(
            success: true,
            clearanceStatus: null,
            reportingStatus: 'REPORTED',
            validationStatus: 'PASS',
            clearedInvoice: null,
            validationResults: [],
            warningMessages: [],
            errorMessages: [],
            rawResponse: null,
        );
    }
}
