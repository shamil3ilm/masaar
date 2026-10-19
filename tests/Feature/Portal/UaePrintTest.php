<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\PrintedInvoice;
use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\FakeAspGateway;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceLinks;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A UAE invoice printed: the e-invoice is the tax invoice, and a buyer not
 * yet on electronic invoicing is given a regular one beside it, "e.g. in
 * pdf" (UAE Electronic Invoicing Guidelines v1.1, 10.2.2, p23); any other
 * buyer may be given a copy on request (6.2, p15), which says it is one.
 *
 * Every paper prints it, with no QR code - the UAE's e-invoices feature none
 * (5.3) - and the PDF is PDF/A with neither the XML nor a seal: no document
 * asks for either on a PDF. A draft prints nothing, and nor does a rejected
 * e-invoice, which is no tax invoice.
 */
class UaePrintTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $this->gulf = $this->uaeOrganization();
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->gulf->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_unregistered_buyer_gets_a_tax_invoice(): void
    {
        $invoice = $this->uaeInvoice($this->gulf, [], null, ['buyer_endpoint' => '0235:9900000098']);
        $show = route('portal.invoices.show', $invoice->id);

        $this->assertSame(PrintedInvoice::REQUIRED, app(PrintedInvoice::class)->status($invoice));
        $this->portal()->get($show)->assertOk()->assertSee(__('Print tax invoice for the buyer'))->assertDontSee('data-invoice-qr', false);

        foreach (PaperSize::cases() as $paper) {
            $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))
                ->assertOk()
                ->assertSee('Tax Invoice')
                ->assertSee($invoice->invoice_number)
                ->assertSee('100123456700003')
                ->assertSee('Issued with the electronic invoice, as the buyer is not yet on electronic invoicing.')
                ->assertDontSee('data-print-qr', false);

            $pdf = app(InvoicePdf::class)->render($invoice, $this->gulf, $paper);
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertStringNotContainsString('/EmbeddedFile', $pdf);
            $this->assertStringNotContainsString('/ETSI.CAdES.detached', $pdf);
        }

        $this->portal()->get(route('portal.invoices.pdf', ['invoiceId' => $invoice->id, 'paper' => 'a4']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->portal()->get(route('portal.invoices.receipt-image', $invoice->id))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    /** A buyer the network finds is given a copy only on request, and it says so. */
    public function test_networked_buyer_gets_a_copy(): void
    {
        $this->app->instance(AspGateway::class, new FakeAspGateway);
        $invoice = $this->uaeInvoice($this->gulf);

        $this->assertSame(PrintedInvoice::COPY, app(PrintedInvoice::class)->status($invoice));
        $this->portal()->get(route('portal.invoices.show', $invoice->id))->assertOk()->assertSee(__('Print a copy'));
        $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => 'a4']))
            ->assertOk()
            ->assertSee('data-print-status="copy"', false)
            ->assertSee('Copy for information. The tax invoice is the electronic invoice delivered through the e-invoicing network.');
    }

    /** No answer from the network: the human-readable version of the e-invoice. */
    public function test_an_unknown_buyer_gets_the_version(): void
    {
        $invoice = $this->uaeInvoice($this->gulf);

        $this->assertSame(PrintedInvoice::VERSION, app(PrintedInvoice::class)->status($invoice));
        $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => 'a4']))
            ->assertOk()->assertSee('Human-readable version of the electronic invoice.');
    }

    /**
     * A reverse-charge line prints the statement Art. 59(1)(l) asks for, citing
     * the clause of Article 48 of the Decree-Law its goods type names.
     */
    public function test_a_reverse_charge_says_so(): void
    {
        $invoice = $this->uaeInvoice($this->gulf, [], [$this->uaeLine(['tax_category' => 'AE', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 1000])], [
            'buyer_endpoint' => '0235:9900000098',
            'lines' => ['1' => ['goods_type' => 'DL8.48.3.1']],
        ]);

        $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => 'a4']))
            ->assertOk()->assertSee('data-print-statement', false)
            ->assertSee('Reverse charge: the Recipient is required to account for Tax on this supply under Clause 3 of Article 48 of Federal Decree-Law No. 8 of 2017 on Value Added Tax.');
    }

    public function test_a_draft_or_rejection_prints_nothing(): void
    {
        $invoice = $this->uaeInvoice($this->gulf, [], null, ['buyer_endpoint' => '0235:9900000098']);
        $show = route('portal.invoices.show', $invoice->id);

        foreach (['draft', 'rejected'] as $status) {
            Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)->toBase()->update(['status' => $status]));

            $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => 'a4']))->assertRedirect($show)->assertSessionHas('error');
            $this->portal()->get(route('portal.invoices.pdf', ['invoiceId' => $invoice->id]))->assertRedirect($show);
        }
    }

    /**
     * No QR code of its own - the e-invoice has none - but the PDF can be
     * handed over by a link, as any printable invoice's can.
     */
    public function test_a_link_hands_it_over(): void
    {
        $invoice = $this->uaeInvoice($this->gulf, [], null, ['buyer_endpoint' => '0235:9900000098']);

        $this->portal()->get(route('portal.invoices.qr', [$invoice->id, 'png']))->assertNotFound();
        $this->portal()->post(route('portal.qr-codes.links.store'), ['invoice_number' => $invoice->invoice_number])->assertSessionHasNoErrors();

        $links = app(InvoiceLinks::class)->active((string) $this->gulf->id);
        $this->assertCount(1, $links);
        $this->get(app(InvoiceLinks::class)->url($links->first()))->assertOk()->assertSee($invoice->invoice_number);
    }

    public function test_the_api_prints_it(): void
    {
        $invoice = $this->uaeInvoice($this->gulf, [], null, ['buyer_endpoint' => '0235:9900000098']);
        $token = JWTAuth::claims(['org_id' => $this->gulf->id, 'role' => 'member'])->fromUser($this->member);

        $this->withToken($token)->get('/api/invoices/'.$invoice->id.'/pdf?paper=80mm')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->withToken($token)->get('/api/invoices/'.$invoice->id.'/receipt-image')->assertOk();
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->gulf->id]);
    }
}
