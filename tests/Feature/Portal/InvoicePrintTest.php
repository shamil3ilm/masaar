<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * The copy the customer is handed.
 *
 * A simplified invoice must be presented to the customer as a printed copy, so
 * a receipt that cannot be printed is not finished. The QR is the part that
 * matters: it is what anyone checking the invoice scans, and it was a base64
 * string on the screen.
 *
 * A standard invoice is different. The authority stamps it and notifies the
 * issuer before it may be shared with the buyer, so until a cleared copy comes
 * back there is no printable copy at all - the same gate as the PDF. The page
 * used to render the uncleared invoice with a notice on it, which is still a
 * printable invoice.
 */
class InvoicePrintTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);

        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, [
            'role' => 'member',
            'status' => 'active',
        ]);
    }

    public function test_the_receipt_draws_the_qr(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-1', [
            'type' => 'simplified',
            'status' => 'issued',
            'icv' => 1,
            'hash' => base64_encode(hash('sha256', 'x', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
        ]);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id.'/print')
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('فاتورة ضريبية مبسطة', false)
            ->assertSee('Acme Trading')
            ->assertDontSee('Switch organization'));
    }

    /**
     * The taxable amount is after the document discount, so the receipt's
     * own sums add up: 100 less 10, plus 13.50 VAT, is 103.50.
     */
    public function test_the_discount_reduces_the_taxable_amount(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-9', [
            'type' => 'simplified',
            'status' => 'issued',
            'icv' => 9,
            'hash' => base64_encode(hash('sha256', 'd', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '13.50',
            'total' => '103.50',
        ]);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id.'/print')
            ->assertOk()
            ->assertSeeInOrder(['Subtotal', '100.00 SAR', 'Discount', '10.00 SAR', 'Taxable amount', '90.00 SAR', '13.50 SAR', '103.50 SAR']));
    }

    /**
     * A standard invoice is not shareable until the authority has cleared it.
     */
    public function test_an_uncleared_standard_is_withheld(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-2', [
            'type' => 'standard',
            'status' => 'issued',
            'icv' => 2,
            'hash' => base64_encode(hash('sha256', 'y', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
        ]);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id.'/print')
            ->assertRedirect(route('portal.invoices.show', $invoice->id))
            ->assertSessionHas('error', __('ZATCA has not cleared this invoice yet. A standard invoice may not be given to the buyer until it is.')));
    }

    /**
     * A draft is not a tax invoice, so it has no printed copy either.
     */
    public function test_a_draft_does_not_print(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-4', ['type' => 'simplified']);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id.'/print')
            ->assertRedirect(route('portal.invoices.show', $invoice->id))
            ->assertSessionHas('error'));
    }

    /**
     * A cleared standard invoice is the document, and prints like one.
     */
    public function test_a_cleared_standard_prints(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-3', [
            'type' => 'standard',
            'status' => 'accepted',
            'icv' => 3,
            'hash' => base64_encode(hash('sha256', 'z', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
            'cleared_xml' => '<Invoice/>',
        ]);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id.'/print')
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('فاتورة ضريبية', false));
    }

    /**
     * Bilingual: the English beside the Arabic, for the buyer and each line,
     * and a minus that stays in front of its amount in a right-to-left page.
     */
    public function test_the_english_prints_beside(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-EN', [
            'type' => 'standard',
            'buyer_name' => 'Gulf Supplies LLC',
            'buyer_name_ar' => 'إمدادات الخليج',
        ]);
        $invoice->lines()->create([
            'description' => 'Office chair', 'description_ar' => 'كرسي مكتب', 'quantity' => '4', 'unit_code' => 'C62',
            'unit_price' => '500.00', 'discount_amount' => '100.00', 'net_amount' => '1900.00', 'tax_rate' => '15.00',
            'tax_amount' => '285.00', 'tax_category' => 'S', 'line_total' => '2185.00',
        ]);
        // Issued and cleared, as a query writes it: the model keeps an issued invoice's lines.
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)->update([
            'status' => 'accepted', 'icv' => 4, 'hash' => base64_encode(hash('sha256', 'e', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='), 'cleared_xml' => '<Invoice/>',
        ]));

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id.'/print')
            ->assertOk()
            ->assertSee('Gulf Supplies LLC')
            ->assertSee('Office chair')
            ->assertSee('<span dir="ltr">-100.00 SAR', false));
    }
}
