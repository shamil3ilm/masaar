<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\Services\UaeInvoiceIssuer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuanceNotifier;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Services\KillSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Issuing a UAE draft: number, moment, status and invoice.issued, then a
 * Peppol document stored and queued once. Nothing of ZATCA's runs.
 */
class UaeIssuingTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->mock(Submitter::class)->shouldNotReceive('generate');
        $this->mock(DocumentBuilder::class)->shouldNotReceive('generateComplianceData');
    }

    public function test_draft_is_issued_and_queued_once(): void
    {
        $this->mock(IssuanceNotifier::class)->shouldReceive('issued')->once();
        $invoice = $this->draft($this->uaeOrganization());

        $first = app(UaeInvoiceIssuer::class)->issue($invoice);
        $second = app(UaeInvoiceIssuer::class)->issue(Invoice::query()->findOrFail($invoice->id));

        $issued = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame('issued', $issued->status->value);
        $this->assertNotNull($issued->issued_at);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d+$/', (string) $issued->invoice_number);
        $this->assertNull($issued->icv);
        $this->assertNull($issued->hash);
        $this->assertNull($issued->qr_code);
        $this->assertSame('ready', $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PeppolDocument::query()->count());
        Queue::assertPushed(SendPeppolDocument::class, 1);
    }

    public function test_problems_refuse_and_leave_draft(): void
    {
        $this->mock(IssuanceNotifier::class)->shouldNotReceive('issued');
        $invoice = $this->draft($this->uaeOrganization(['emirate' => null]));

        try {
            app(UaeInvoiceIssuer::class)->issue($invoice);
            $this->fail('An invoice missing mandatory fields was issued.');
        } catch (IssuanceRefusedException $e) {
            $this->assertContains('Seller country subdivision is missing.', $e->problems);
        }

        $this->assertSame('draft', $invoice->status->value);
        $this->assertNull($invoice->invoice_number);
        $this->assertNull(Invoice::query()->findOrFail($invoice->id)->invoice_number);
        $this->assertSame(0, PeppolDocument::query()->count());
        Queue::assertNothingPushed();
    }

    /** The e-invoice and the PDF must never show two totals for one invoice. */
    public function test_disagreeing_totals_are_refused(): void
    {
        $invoice = $this->draft($this->uaeOrganization(), ['tax_amount' => '49.99', 'total' => '1049.99']);

        try {
            app(UaeInvoiceIssuer::class)->issue($invoice);
            $this->fail('An invoice whose totals disagree with its e-invoice was issued.');
        } catch (IssuanceRefusedException $e) {
            $this->assertSame([
                'Total: the invoice says 1049.99 and its e-invoice 1050.00.',
                'VAT: the invoice says 49.99 and its e-invoice 50.00.',
            ], $e->problems);
        }

        $this->assertSame('draft', Invoice::query()->findOrFail($invoice->id)->status->value);
    }

    /**
     * The guards Saudi issuing has always had: an organization still under
     * review issues nothing, and keeps neither a number nor a document.
     */
    public function test_unapproved_organization_is_refused(): void
    {
        $organization = $this->uaeOrganization();
        $organization->forceFill(['status' => Organization::STATUS_PENDING_REVIEW])->save();
        $invoice = $this->draft($organization);

        try {
            app(UaeInvoiceIssuer::class)->issue($invoice);
            $this->fail('An organization under review issued a UAE invoice.');
        } catch (IssuanceRefusedException) {
        }

        $this->assertSame('draft', Invoice::query()->findOrFail($invoice->id)->status->value);
        $this->assertNull(Invoice::query()->findOrFail($invoice->id)->invoice_number);
        $this->assertSame(0, PeppolDocument::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_issuance_stop_refuses_uae_too(): void
    {
        app(KillSwitch::class)->enable(KillSwitch::SWITCH_ISSUANCE, reason: 'signing defect');

        $this->expectException(IssuanceRefusedException::class);

        app(UaeInvoiceIssuer::class)->issue($this->draft($this->uaeOrganization()));
    }

    public function test_organization_without_profile_is_refused(): void
    {
        $this->expectException(IssuanceRefusedException::class);

        app(UaeInvoiceIssuer::class)->issue($this->draft(Organization::create(['name' => 'Gulf', 'country' => 'AE'])));
    }

    /**
     * An unnumbered draft with its stored totals, as the API drafts one.
     *
     * @param  array<string, string>  $totals
     */
    private function draft(Organization $organization, array $totals = []): Invoice
    {
        $invoice = $this->uaeInvoice($organization, ['invoice_number' => null]);
        Invoice::query()->whereKey($invoice->id)->update([
            'status' => 'draft',
            ...array_merge(['subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00'], $totals),
        ]);

        return Invoice::query()->findOrFail($invoice->id);
    }
}
