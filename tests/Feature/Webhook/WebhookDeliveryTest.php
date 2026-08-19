<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Domains\Compliance\Fatoora\Events\InvoiceCleared;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A webhook is the only thing an integrator sees, and nothing tested it.
 *
 * The payload carried 'total_amount' => $invoice->total_with_vat for the life
 * of the platform. There is no such column, so Eloquent returned null and every
 * webhook ever sent reported a null amount. It was fixed by reading the schema,
 * not by any test failing — because no test looked at what was delivered.
 *
 * These drive the real path: the event that ProcessFatooraSubmission raises,
 * through the registered listener, through WebhookService, to an outbound HTTP
 * request. Only the network is faked.
 */
class WebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
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
    }

    public function test_clearance_reaches_the_endpoint(): void
    {
        $this->subscribe(['invoice.cleared']);

        Event::dispatch(new InvoiceCleared($this->submission()));

        Http::assertSent(fn ($request) => $request->url() === 'https://erp.test/hooks');
    }

    /**
     * The amount is the field that was null for the platform's whole life.
     */
    public function test_payload_carries_the_total(): void
    {
        $this->subscribe(['invoice.cleared']);

        Event::dispatch(new InvoiceCleared($this->submission()));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['data']['total_amount'] ?? null) === '115.00'
                && ($body['data']['invoice_number'] ?? null) === 'INV-1'
                && ($body['event'] ?? null) === 'invoice.cleared';
        });
    }

    /**
     * The receiver authenticates the call by this signature, so a change to how
     * the body is serialised silently breaks every integrator at once.
     */
    public function test_signature_verifies_against_the_secret(): void
    {
        $this->subscribe(['invoice.cleared']);

        Event::dispatch(new InvoiceCleared($this->submission()));

        Http::assertSent(function ($request) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), 'shhh');

            return $request->header('X-Webhook-Signature')[0] === $expected;
        });
    }

    /**
     * Subscriptions are per event. Sending everything to everyone would leak
     * one tenant's activity into another integrator's log.
     */
    public function test_unsubscribed_event_is_not_sent(): void
    {
        $this->subscribe(['invoice.rejected']);

        Event::dispatch(new InvoiceCleared($this->submission()));

        Http::assertNothingSent();
    }

    public function test_inactive_endpoint_is_not_called(): void
    {
        $this->subscribe(['invoice.cleared'], active: false);

        Event::dispatch(new InvoiceCleared($this->submission()));

        Http::assertNothingSent();
    }

    /**
     * @param  list<string>  $events
     */
    private function subscribe(array $events, bool $active = true): void
    {
        Webhook::withoutTenantScope(fn () => Webhook::create([
            'org_id' => $this->organization->id,
            'url' => 'https://erp.test/hooks',
            'secret' => 'shhh',
            'events' => $events,
            'is_active' => $active,
        ]));
    }

    private function submission(): InvoiceSubmission
    {
        return InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $this->invoice->id,
            'org_id' => $this->organization->id,
            'state' => 'cleared',
            'submission_type' => 'clearance',
        ]));
    }
}
