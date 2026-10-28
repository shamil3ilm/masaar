<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\HttpAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\NullAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Sending\ParticipantDirectory;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The recipient looked up on the network before a UAE invoice is issued
 * (UAE Electronic Invoicing Guidelines v1.1, p11 rows 5-6; programme
 * presentation p7): not registered stops it with what to do; not known
 * stops nothing.
 */
class ParticipantLookupTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private FakeAspGateway $asp;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->asp = new FakeAspGateway;
        $this->app->instance(AspGateway::class, $this->asp);
    }

    public function test_unregistered_recipient_refuses_the_issue(): void
    {
        $this->asp->unregistered('0235:1007654321');
        $draft = $this->draft();

        try {
            app(InvoiceIssuer::class)->issue($draft);
            $this->fail('Issued to a recipient not on the network.');
        } catch (IssuanceRefusedException $e) {
            $this->assertStringContainsString('0235:1007654321 is not registered', implode(' ', $e->problems));
        }

        $this->assertSame('draft', $draft->fresh()->status->value);
        $this->assertSame(0, PeppolDocument::query()->count());
    }

    public function test_registered_recipient_is_issued(): void
    {
        app(InvoiceIssuer::class)->issue($this->draft());

        $this->assertSame(['0235:1007654321'], $this->asp->participantsLookedUp);
        $this->assertSame(1, PeppolDocument::query()->count());
    }

    /** No provider: whether it is registered is unknown, and nothing is stopped. */
    public function test_unknown_answer_stops_nothing(): void
    {
        $this->app->instance(AspGateway::class, new NullAspGateway);

        app(InvoiceIssuer::class)->issue($this->draft());

        $this->assertSame(1, PeppolDocument::query()->count());
    }

    /** Reserved endpoints are never delivered to, so never looked up; answers are kept a while. */
    public function test_reserved_and_known_are_not_asked(): void
    {
        $directory = app(ParticipantDirectory::class);

        $this->assertNull($directory->reachable('0235:9900000098'));
        $this->assertTrue($directory->reachable('0235:1007654321'));
        $this->assertTrue($directory->reachable('iso6523-actorid-upis::0235:1007654321'));

        $this->assertSame(['0235:1007654321'], $this->asp->participantsLookedUp);
    }

    public function test_provider_without_answer_is_unknown(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->app->instance(AspGateway::class, new HttpAspGateway('https://93.184.215.14/api', 'id', 'secret', new WebhookSignature('s')));

        $this->assertNull(app(ParticipantDirectory::class)->reachable('0235:1000000001'));
    }

    private function draft(): Invoice
    {
        $invoice = $this->uaeInvoice($this->uaeOrganization(), ['invoice_number' => null, 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00'], issued: false);

        return Invoice::query()->findOrFail($invoice->id);
    }
}
