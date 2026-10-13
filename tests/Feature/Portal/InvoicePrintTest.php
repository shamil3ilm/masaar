<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
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
 * back the printable view withholds the document rather than pretending.
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
            ->assertOk()
            ->assertSee('not yet cleared')
            ->assertDontSee('<svg', false));
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
            ->assertDontSee('not yet cleared'));
    }
}
