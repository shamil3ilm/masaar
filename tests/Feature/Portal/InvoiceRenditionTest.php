<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * The invoice a person reads is in Arabic as well as English.
 *
 * Article 66(2) of the VAT Implementing Regulations requires invoices to be
 * issued in Arabic. ZATCA's Guideline for Tax Invoicing and Records (v3, May
 * 2026) 4.2.1 states it as "All fields of the tax invoice must be completed in
 * the Arabic language. The information stated in Arabic shall be considered
 * the approved and conclusive information, and any other language shall be
 * treated as a translation only."
 *
 * The same section then exempts most of what this application stores. Proper
 * names - people, places, the registered branch, product names - may be
 * written in Latin characters, as may numerals, currency codes, dates and
 * invoice numbers. So the requirement does not land on the data. It lands on
 * everything around it: the document title and the caption of every field,
 * which were English only.
 *
 * The Arabic here is copied from the authority's own illustrative invoices in
 * the annex of that guideline rather than translated, so the words on a Masaar
 * invoice are the words ZATCA prints on its examples.
 */
class InvoiceRenditionTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, [
            'role' => 'member',
            'status' => 'active',
        ]);
    }

    /**
     * ZATCA's annex titles the two documents exactly so.
     */
    public function test_a_simplified_invoice_is_titled(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-1', ['type' => 'simplified']);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee('فاتورة ضريبية مبسطة', false)
            ->assertSee('Simplified tax invoice'));
    }

    public function test_a_standard_invoice_is_titled(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-2', ['type' => 'standard']);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee('فاتورة ضريبية', false));
    }

    /**
     * Every caption carries its Arabic, and the Arabic is marked as Arabic.
     *
     * The lang and dir attributes are not decoration: without them a browser
     * shapes the text with the surrounding left-to-right context and a screen
     * reader announces it in the wrong voice.
     */
    public function test_the_captions_carry_arabic(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-3', ['type' => 'simplified']);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        $response = $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id));

        $response->assertOk();

        // Straight from the annex's column headings and totals block.
        foreach ([
            'العميل',                                  // Buyer
            'تفاصيل السلع أو الخدمات',                 // Nature of goods or services
            'الكمية',                                  // Quantity
            'سعر الوحدة',                              // Unit price
            // The column holds each line's VAT amount; "tax rate" was the
            // receipt's other heading, and named the wrong figure here.
            'مبلغ الضريبة',                            // VAT amount
            'الإجمالي (باستثناء ضريبة القيمة المضافة)', // Total excluding VAT
            'مجموع ضريبة القيمة المضافة',              // Total VAT
            'إجمالي المبلغ المستحق',                   // Total amount due
        ] as $caption) {
            $response->assertSee($caption, false);
        }

        $response->assertSee('lang="ar"', false);
        $response->assertSee('dir="rtl"', false);
    }

    /**
     * And a Latin product name is left alone.
     *
     * "ZATCA approves the use of Latin characters for writing such names as
     * part of the Arabic language requirements in the tax invoice." Nothing
     * here should be nagging a seller to translate a product name.
     */
    public function test_a_latin_description_is_shown(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-4');

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting hours',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee('Consulting hours'));
    }
}
