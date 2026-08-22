<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Fatoora\Listeners\DispatchInvoiceWebhook;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where queued work is dispatched to.
 *
 * A job on a queue nobody works waits for ever, and nothing about that looks
 * like a failure: the request succeeds, the record says queued, and the
 * document is never submitted. The names therefore have to be the ones an
 * operator was told to run workers on.
 *
 * The job hardcoded 'zatca-submissions' and the listener 'webhooks', while
 * fatoora.queue.name, .webhooks_queue and .connection existed and were read
 * nowhere. Renaming a queue in configuration moved nothing, and
 * ZATCA_QUEUE_CONNECTION said redis while the application's queue is the
 * database — so an operator who set it and started a redis worker would have
 * watched an empty queue while the jobs went somewhere else.
 */
class QueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_uses_the_configured_queue(): void
    {
        config(['fatoora.queue.name' => 'renamed-submissions']);

        $job = new ProcessFatooraSubmission($this->submission());

        $this->assertSame('renamed-submissions', $job->queue);
    }

    public function test_webhooks_use_the_configured_queue(): void
    {
        config(['fatoora.queue.webhooks_queue' => 'renamed-webhooks']);

        $this->assertSame('renamed-webhooks', app(DispatchInvoiceWebhook::class)->viaQueue());
    }

    /**
     * Unset means the application's own connection, so a deployment that has
     * not asked for a separate one is not sent to a connection it never
     * configured.
     */
    public function test_no_connection_configured_uses_the_default(): void
    {
        config(['fatoora.queue.connection' => '']);

        $job = new ProcessFatooraSubmission($this->submission());

        $this->assertNull($job->connection);
        $this->assertNull(app(DispatchInvoiceWebhook::class)->viaConnection());
    }

    public function test_a_configured_connection_is_used(): void
    {
        config(['fatoora.queue.connection' => 'redis']);

        $job = new ProcessFatooraSubmission($this->submission());

        $this->assertSame('redis', $job->connection);
        $this->assertSame('redis', app(DispatchInvoiceWebhook::class)->viaConnection());
    }

    /**
     * Submissions and webhook deliveries stay apart, so a slow customer
     * endpoint cannot delay a clearance behind it.
     */
    public function test_the_two_queues_are_separate(): void
    {
        $job = new ProcessFatooraSubmission($this->submission());

        $this->assertNotSame($job->queue, app(DispatchInvoiceWebhook::class)->viaQueue());
    }

    private function submission(): InvoiceSubmission
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-1',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        return InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id,
            'org_id' => $organization->id,
            'state' => 'queued',
            'submission_type' => 'clearance',
        ]));
    }
}
