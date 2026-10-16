<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\Services\DocumentStatus;
use App\Domains\Compliance\Peppol\Services\PeppolPreparer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Compliance\Peppol\PintInvoices;
use Tests\TestCase;

/**
 * A UAE invoice's page: where its e-invoice stands in place of the ZATCA
 * counter, hash and QR, and its XML to download. The Saudi actions built on
 * that hash - print, PDF, email, notes - are not offered.
 */
class UaeInvoicePageTest extends TestCase
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

    public function test_issued_invoice_shows_its_einvoice(): void
    {
        $invoice = $this->uaeInvoice($this->gulf);
        app(PeppolPreparer::class)->prepare($invoice);

        $this->page($invoice)
            ->assertOk()
            ->assertSee('UAE e-invoice')
            ->assertSee('Ready to send')
            ->assertSee('Not sent yet')
            ->assertSee(route('portal.invoices.xml', $invoice->id), false)
            ->assertSee('TRN 100765432100003')
            ->assertDontSee('Counter (ICV)')
            ->assertDontSee(route('portal.invoices.print', $invoice->id), false)
            ->assertDontSee(route('portal.invoices.pdf', $invoice->id), false)
            ->assertDontSee(route('portal.invoices.adjust', [$invoice->id, 'debit_note']), false)
            ->assertDontSee(route('portal.invoices.send', $invoice->id), false);
    }

    /** What the provider said, said again: status, message id and errors. */
    public function test_refusal_shows_message_and_errors(): void
    {
        $invoice = $this->uaeInvoice($this->gulf);
        $document = app(PeppolPreparer::class)->prepare($invoice);
        PeppolDocument::withoutTenantScope(fn () => $document->forceFill([
            'status' => DocumentStatus::REJECTED,
            'asp_message_id' => 'msg-out-42',
            'mls_code' => 'RE',
            'errors' => ['IBR-128-AE: <b>emirate</b> missing'],
            'last_error' => 'Refused by the receiving provider',
        ])->save());

        $this->page($invoice)
            ->assertOk()
            ->assertSee('Rejected')
            ->assertSee('msg-out-42')
            ->assertSee('RE')
            ->assertSee('IBR-128-AE: &lt;b&gt;emirate&lt;/b&gt; missing', false)
            ->assertSee('Refused by the receiving provider');
    }

    public function test_draft_offers_issue_as_einvoice(): void
    {
        $invoice = $this->uaeInvoice($this->gulf);
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)->update(['status' => 'draft']));

        $this->page($invoice)
            ->assertOk()
            ->assertSee(route('portal.invoices.issue', $invoice->id), false)
            ->assertSee('sent to the buyer as a UAE e-invoice')
            ->assertDontSee(route('portal.invoices.xml', $invoice->id), false);
    }

    public function test_einvoice_downloads_as_xml(): void
    {
        $invoice = $this->uaeInvoice($this->gulf);
        $document = app(PeppolPreparer::class)->prepare($invoice);

        $response = $this->asRequest(fn () => $this->actingAs($this->member)
            ->withSession(['portal_org_id' => $this->gulf->id])
            ->get("/portal/invoices/{$invoice->id}/e-invoice")
            ->assertOk());

        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame($document->xml, $response->getContent());
    }

    public function test_rival_einvoice_is_not_found(): void
    {
        $rival = $this->uaeOrganization();
        $invoice = $this->uaeInvoice($rival);
        app(PeppolPreparer::class)->prepare($invoice);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->withSession(['portal_org_id' => $this->gulf->id])
            ->get("/portal/invoices/{$invoice->id}/e-invoice")
            ->assertNotFound());
        $this->page($invoice)->assertNotFound();
    }

    private function page(Invoice $invoice): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->member)
            ->withSession(['portal_org_id' => $this->gulf->id])
            ->get("/portal/invoices/{$invoice->id}"));
    }
}
