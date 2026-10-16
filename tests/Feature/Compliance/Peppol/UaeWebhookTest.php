<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Compliance\Fatoora\Events\InvoiceCleared;
use App\Domains\Compliance\Fatoora\Events\InvoiceFailed;
use App\Domains\Compliance\Fatoora\Events\InvoiceRejected;
use App\Domains\Compliance\Fatoora\Events\InvoiceReported;
use App\Domains\Compliance\Fatoora\Events\InvoiceSubmitted;
use App\Domains\Compliance\Fatoora\Events\InvoiceWarning;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\Services\UaeInvoiceIssuer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuanceNotifier;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * What an integrator hears about a UAE invoice: invoice.issued in the
 * invoice's own currency with its e-invoice, and none of ZATCA's fields.
 */
class UaeWebhookTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    /** @var list<array{event: string, payload: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $webhooks = Mockery::mock(WebhookService::class);
        $webhooks->shouldReceive('dispatch')->andReturnUsing(function (string $org, string $event, array $payload): void {
            $this->sent[] = ['event' => $event, 'payload' => $payload];
        });
        $this->app->instance(WebhookService::class, $webhooks);
    }

    public function test_issued_payload_carries_the_einvoice(): void
    {
        $zatca = [InvoiceSubmitted::class, InvoiceCleared::class, InvoiceReported::class, InvoiceRejected::class, InvoiceWarning::class, InvoiceFailed::class];
        Event::fake($zatca);
        $invoice = $this->draft($this->uaeOrganization(), ['currency' => 'USD', 'exchange_rate' => '3.6725']);

        app(UaeInvoiceIssuer::class)->issue($invoice);

        $document = PeppolDocument::query()->sole();
        $issued = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame([[
            'event' => 'invoice.issued',
            'payload' => [
                'invoice_id' => $issued->id,
                'invoice_number' => $issued->invoice_number,
                'status' => 'issued',
                'type' => 'standard',
                'total' => '1050.00',
                'currency' => 'USD',
                'e_invoice' => ['id' => $document->id, 'status' => 'ready', 'message_id' => null],
            ],
        ]], $this->sent);
        foreach ($zatca as $event) {
            Event::assertNotDispatched($event);
        }
    }

    /** Saudi's payload has no extra fields: the golden masters pin its bytes. */
    public function test_saudi_payload_keeps_its_shape(): void
    {
        $invoice = $this->draft($this->uaeOrganization());

        app(IssuanceNotifier::class)->issued($invoice);

        $this->assertSame(['invoice_id', 'invoice_number', 'status', 'type', 'total', 'currency'], array_keys($this->sent[0]['payload']));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function draft(Organization $organization, array $attributes = []): Invoice
    {
        $invoice = $this->uaeInvoice($organization, ['invoice_number' => null, ...$attributes]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => 'draft', 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00']);

        return Invoice::query()->findOrFail($invoice->id);
    }
}
