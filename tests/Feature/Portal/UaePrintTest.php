<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Services\InvoiceExtraQr;
use App\Domains\Invoice\Services\InvoiceLinks;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A UAE invoice reaches its buyer as the e-invoice itself, so printing
 * changes nothing for it: no paper of any size, no receipt image, no
 * ESC/POS, no ZATCA QR and no invoice link - the module's answer, as before.
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

    public function test_no_paper_prints_it(): void
    {
        $invoice = $this->uaeInvoice($this->gulf);
        app(InvoiceExtraQr::class)->setChoices($this->gulf, [PaperSize::Roll80->value => InvoiceExtraQr::INVOICE_LINK]);
        $portal = fn () => $this->actingAs($this->member)->withSession(['portal_org_id' => $this->gulf->id]);
        $show = route('portal.invoices.show', $invoice->id);

        foreach (PaperSize::cases() as $paper) {
            $portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))->assertRedirect($show)->assertSessionHas('error');
            $portal()->get(route('portal.invoices.pdf', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))->assertRedirect($show);
        }

        $portal()->get(route('portal.invoices.receipt-image', $invoice->id))->assertRedirect($show);
        $portal()->get(route('portal.invoices.qr', [$invoice->id, 'png']))->assertRedirect($show);
        $portal()->post(route('portal.qr-codes.links.store'), ['invoice_number' => $invoice->invoice_number])->assertSessionHasErrors('invoice_number', null, 'link');
        $portal()->get($show)->assertOk()->assertDontSee('data-invoice-qr', false)->assertDontSee('data-business-qr', false);

        $this->assertCount(0, app(InvoiceLinks::class)->active((string) $this->gulf->id));
    }

    public function test_the_api_prints_nothing(): void
    {
        $invoice = $this->uaeInvoice($this->gulf);
        $token = JWTAuth::claims(['org_id' => $this->gulf->id, 'role' => 'member'])->fromUser($this->member);

        $this->withToken($token)->getJson('/api/invoices/'.$invoice->id.'/pdf?paper=80mm')->assertStatus(409);
        $this->withToken($token)->getJson('/api/invoices/'.$invoice->id.'/receipt-image')->assertStatus(409);
        $this->withToken($token)->getJson('/api/invoices/'.$invoice->id.'/escpos')->assertStatus(409);
    }
}
