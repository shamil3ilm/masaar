<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The pipeline's auto_submit for a UAE invoice: false issues it and prepares
 * its e-invoice without queueing it; an explicit send queues it once, and
 * never a document already on its way.
 *
 * The pipeline's own payload has no UAE fields, so each case continues a
 * UAE draft an earlier call under the same Idempotency-Key made.
 */
class UaeAutoSubmitTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $this->gulf = $this->uaeOrganization();
    }

    public function test_held_back_invoice_is_not_queued(): void
    {
        $invoice = $this->draft('erp-1');

        $result = $this->pipeline('erp-1', false);
        $document = PeppolDocument::query()->sole();

        $this->assertSame('issued', $result['status']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(['submission_id' => $document->id, 'state' => 'ready', 'clearance_status' => null, 'reporting_status' => null], $result['zatca_response']);
        $this->assertSame([
            'invoice_id', 'uuid', 'invoice_number', 'erp_reference_id', 'status',
            'compliance_status', 'hash', 'qr_code', 'signed_xml', 'cleared_xml',
            'zatca_response', 'totals', 'errors', 'warnings',
        ], array_keys($result));
        $this->assertSame($invoice->id, $document->invoice_id);
        $this->assertNull($document->queued_at);
        Queue::assertNothingPushed();
    }

    public function test_send_queues_held_back_once(): void
    {
        $invoice = $this->draft('erp-1');
        $this->pipeline('erp-1', false);

        $this->api()->postJson("/api/compliance/ae/invoices/{$invoice->id}/send")
            ->assertOk()
            ->assertJsonPath('data.invoice_status', 'issued')
            ->assertJsonPath('data.status', 'ready');
        $this->api()->postJson("/api/compliance/ae/invoices/{$invoice->id}/send")->assertOk();

        Queue::assertPushed(SendPeppolDocument::class, 1);
        $this->assertNotNull(PeppolDocument::query()->sole()->queued_at);
    }

    public function test_send_never_requeues_moving_documents(): void
    {
        foreach (['sending', 'sent', 'delivered', 'failed'] as $status) {
            $invoice = $this->draft('erp-'.$status);
            $this->pipeline('erp-'.$status, false);
            PeppolDocument::query()->where('invoice_id', $invoice->id)->update(['status' => $status]);

            $this->api()->postJson("/api/compliance/ae/invoices/{$invoice->id}/send")
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        Queue::assertNothingPushed();
    }

    public function test_retry_with_auto_submit_queues(): void
    {
        $this->draft('erp-1');

        $this->pipeline('erp-1', false);
        $this->pipeline('erp-1', true);
        $this->pipeline('erp-1', true);

        Queue::assertPushed(SendPeppolDocument::class, 1);
    }

    public function test_auto_submit_default_queues(): void
    {
        $this->draft('erp-1');

        $result = app(PipelineService::class)->submitInvoice([], $this->gulf->id, idempotencyKey: 'erp-1');

        $this->assertSame('issued', $result['status']);
        Queue::assertPushed(SendPeppolDocument::class, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function pipeline(string $key, bool $autoSubmit): array
    {
        return app(PipelineService::class)->submitInvoice(['auto_submit' => $autoSubmit], $this->gulf->id, idempotencyKey: $key);
    }

    /** A UAE draft an earlier pipeline call made under this key. */
    private function draft(string $key): Invoice
    {
        $invoice = $this->uaeInvoice($this->gulf, ['invoice_number' => null, 'idempotency_key' => $key]);
        Invoice::query()->whereKey($invoice->id)->update([
            'status' => 'draft', 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00',
        ]);

        return Invoice::query()->findOrFail($invoice->id);
    }

    private function api(): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->gulf->id, ['role' => 'admin', 'status' => 'active']);

        return $this->withToken(JWTAuth::claims(['org_id' => $this->gulf->id, 'role' => 'admin'])->fromUser($user));
    }
}
