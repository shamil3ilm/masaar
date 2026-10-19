<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\SaudiArabia\Events\InvoiceCleared;
use App\Domains\Compliance\Countries\SaudiArabia\Events\InvoiceFailed;
use App\Domains\Compliance\Countries\SaudiArabia\Events\InvoiceRejected;
use App\Domains\Compliance\Countries\SaudiArabia\Events\InvoiceReported;
use App\Domains\Compliance\Countries\SaudiArabia\Events\InvoiceSubmitted;
use App\Domains\Compliance\Countries\SaudiArabia\Events\InvoiceWarning;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
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

        app(InvoiceIssuer::class)->issue($invoice);

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
                'e_invoice' => ['id' => $document->id, 'status' => 'ready', 'reporting_status' => null, 'message_id' => null],
            ],
        ]], $this->sent);
        foreach ($zatca as $event) {
            Event::assertNotDispatched($event);
        }
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
