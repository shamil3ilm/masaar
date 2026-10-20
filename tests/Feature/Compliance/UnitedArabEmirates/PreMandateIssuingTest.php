<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Models\ProviderAppointment;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Module;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStart;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Networks\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentStatus;
use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Before its e-invoicing start date a UAE business issues ordinary tax
 * invoices (VAT Executive Regulation, Article 59) and keeps them (UAE
 * Electronic Invoicing Guidelines v1.1, 5.4): its PINT AE document is still
 * built, checked and stored, and never sent - then or later, since the
 * mandate is not retroactive. From the date, every invoice goes through its
 * appointed provider (6.1), and with none appointed issuing is refused.
 */
class PreMandateIssuingTest extends TestCase
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

    public function test_no_date_issues_without_transmitting(): void
    {
        $document = app(InvoiceIssuer::class)->issue($this->draft($this->uaeOrganization(['einvoicing_start' => null], appointed: false)));

        $this->assertSame('issued', Invoice::query()->sole()->status->value);
        $this->assertSame(DocumentStatus::NOT_TRANSMITTED, $document->status);
        $this->assertStringContainsString('<cbc:CustomizationID>urn:peppol:pint:billing-1@ae-1</cbc:CustomizationID>', (string) $document->xml);
        $this->assertNull($document->fresh()->queued_at);
        Queue::assertNothingPushed();
    }

    public function test_future_date_is_still_before(): void
    {
        $organization = $this->uaeOrganization(['einvoicing_start' => now()->addMonth()->toDateString()], appointed: false);

        $document = app(InvoiceIssuer::class)->issue($this->draft($organization));

        $this->assertSame(DocumentStatus::NOT_TRANSMITTED, $document->status);
        Queue::assertNothingPushed();
    }

    /** Nothing is sent, so the recipient is not looked up on the network either. */
    public function test_recipient_not_looked_up_before(): void
    {
        $this->asp->unregistered('0235:1007654321');

        $document = app(InvoiceIssuer::class)->issue($this->draft($this->uaeOrganization(['einvoicing_start' => null])));

        $this->assertSame(DocumentStatus::NOT_TRANSMITTED, $document->status);
        $this->assertSame([], $this->asp->participantsLookedUp);
    }

    /** The document's own checks still refuse before the start date. */
    public function test_mandatory_fields_still_refuse_before(): void
    {
        try {
            app(InvoiceIssuer::class)->issue($this->draft($this->uaeOrganization(['einvoicing_start' => null, 'emirate' => null])));
            $this->fail('An invoice missing mandatory fields was issued before the start date.');
        } catch (IssuanceRefusedException $e) {
            $this->assertContains('Seller country subdivision is missing.', $e->problems);
        }

        $this->assertSame(0, PeppolDocument::query()->count());
    }

    /** The mandate is not retroactive: a later date, a provider, a send - none queues it. */
    public function test_changing_date_later_never_queues(): void
    {
        $organization = $this->uaeOrganization(['einvoicing_start' => null], appointed: false);
        $draft = $this->draft($organization);
        $document = app(InvoiceIssuer::class)->issue($draft);

        app(EinvoicingStart::class)->set($organization, now()->subDay()->toDateString(), null);
        $this->appoint($organization);

        app(InvoiceIssuer::class)->issue(Invoice::query()->findOrFail($draft->id));
        app(Module::class)->resume(Invoice::query()->findOrFail($draft->id), $organization, new IssueOptions(file: true));
        $this->assertFalse(app(PeppolSender::class)->queueOnce($document));
        $this->assertFalse(app(PeppolSender::class)->send((string) $document->id));

        $this->assertSame(DocumentStatus::NOT_TRANSMITTED, $document->fresh()->status);
        $this->assertNull($document->fresh()->queued_at);
        $this->assertSame([], $this->asp->submitted);
        Queue::assertNothingPushed();
    }

    public function test_after_start_without_provider_refused(): void
    {
        $draft = $this->draft($this->uaeOrganization(['einvoicing_start' => now()->subDay()->toDateString()], appointed: false));

        try {
            app(InvoiceIssuer::class)->issue($draft);
            $this->fail('Issued after the start date with no service provider appointed.');
        } catch (IssuanceRefusedException $e) {
            $this->assertStringContainsString('no e-invoicing service provider is recorded as appointed', implode(' ', $e->problems));
        }

        $this->assertSame('draft', $draft->fresh()->status->value);
        $this->assertNull($draft->fresh()->invoice_number);
        $this->assertSame(0, PeppolDocument::query()->count());
    }

    public function test_after_start_with_provider_queues(): void
    {
        $document = app(InvoiceIssuer::class)->issue($this->draft($this->uaeOrganization(['einvoicing_start' => now()->toDateString()])));

        $this->assertSame(DocumentStatus::READY, $document->status);
        Queue::assertPushed(SendPeppolDocument::class, 1);
    }

    /** The engine reports it issued and, deliberately, not filed. */
    public function test_engine_says_issued_not_filed(): void
    {
        $organization = $this->uaeOrganization(['einvoicing_start' => null], appointed: false);

        $outcome = app(Module::class)->issue($this->draft($organization), $organization, new IssueOptions(file: true));

        $this->assertTrue($outcome->issued);
        $this->assertFalse($outcome->attemptedFiling);
        $this->assertStringContainsString('not transmitted', app(Module::class)->notFiled());
    }

    public function test_status_reads_issued_not_transmitted(): void
    {
        $html = view('networks.peppol.status', ['status' => DocumentStatus::NOT_TRANSMITTED])->render();

        $this->assertStringContainsString('Issued, not transmitted: before e-invoicing start', $html);
    }

    private function appoint(Organization $organization): void
    {
        ProviderAppointment::withoutTenantScope(fn () => ProviderAppointment::query()->create([
            'org_id' => $organization->id, 'provider' => 'platform', 'status' => ProviderAppointment::APPOINTED, 'appointed_at' => now(),
        ]));
    }

    private function draft(Organization $organization): Invoice
    {
        $invoice = $this->uaeInvoice($organization, ['invoice_number' => null]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => 'draft', 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00']);

        return Invoice::query()->findOrFail($invoice->id);
    }
}
