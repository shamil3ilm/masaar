<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What the printed copy has to carry, per ZATCA's own data dictionary.
 *
 * The dictionary has two columns the XML work never needed: "Visibility on
 * Invoice for Tax invoices" and "Visibility on human readable formats for
 * Simplified Tax invoices". They say which business terms must appear on the
 * copy a person is handed, and they are not the same as the terms the XML
 * must carry.
 *
 * Measured against them, the printed invoice was missing most of an address.
 * BT-35 street, BT-36 additional street, KSA-17 building number, KSA-3
 * district, BT-37 city, BT-38 postal code and BT-40 country are each marked
 * Required for both document types, and the page showed the supplier's name
 * and VAT number and stopped. The XML had the address all along, so nothing
 * in the compliance suite noticed: the document ZATCA holds was right and the
 * one the customer got was not.
 *
 * Also missing: KSA-5 supply date on both; and on a standard invoice BT-131
 * line net amount, BT-152 line VAT rate, KSA-11 line VAT amount and BT-120
 * exemption reason. KSA-12 is "Required with a statement saying 'inclusive of
 * VAT'" on a simplified invoice - the figure was there, the statement was
 * not, so the number was ambiguous.
 *
 * BT-115 amount due for payment is deliberately absent: the dictionary makes
 * it conditional on an advance payment (BT-113) or a rounding amount
 * (BT-114), and this platform stores neither.
 *
 * BT-130, the unit of measure, is marked Not Required on both. It is shown
 * anyway, because a quantity of 2 means nothing on its own.
 */
class PrintFieldTest extends TestCase
{
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
            'street' => 'King Fahd Road',
            'additional_street' => 'Olaya Tower',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    /**
     * The seller's address, which was absent entirely.
     */
    public function test_a_receipt_shows_the_seller_address(): void
    {
        $this->print($this->invoice('simplified'))
            ->assertSee('1234 King Fahd Road')
            ->assertSee('Olaya Tower')
            ->assertSee('Al Olaya')
            ->assertSee('Riyadh 12345')
            ->assertSee('SA');
    }

    /**
     * KSA-5, required on both and shown on neither.
     */
    public function test_a_receipt_shows_the_supply_date(): void
    {
        $this->print($this->invoice('simplified', ['supply_date' => '2026-03-04']))
            ->assertSee('2026-03-04')
            // Both dates are cast, so printing one directly gives a time of
            // day on a document whose BT-2 is a date.
            ->assertDontSee('00:00:00');
    }

    /**
     * KSA-12 on a simplified invoice needs the words, not just the figure.
     */
    public function test_a_receipt_says_inclusive_of_vat(): void
    {
        $this->print($this->invoice('simplified'))->assertSee('inclusive of VAT');
    }

    /**
     * A standard invoice has to break the VAT down per line.
     */
    public function test_a_standard_breaks_vat_down(): void
    {
        $response = $this->print($this->invoice('standard'));

        $response->assertSee('100.00 SAR');  // BT-131, the line net
        $response->assertSee('15%');         // BT-152, the rate
        $response->assertSee('15.00 SAR');   // KSA-11, the line VAT
    }

    /**
     * And it has to name the buyer's address.
     */
    public function test_a_standard_shows_the_buyer_address(): void
    {
        $this->print($this->invoice('standard', [
            'buyer_address' => [
                'street' => 'Prince Sultan Road',
                'building_number' => '8899',
                'district' => 'Al Rawdah',
                'city' => 'Jeddah',
                'postal_code' => '23435',
                'country_code' => 'SA',
            ],
        ]))->assertSee('8899 Prince Sultan Road')->assertSee('Jeddah 23435');
    }

    /**
     * A simplified invoice does not: it is a receipt for someone who gave no
     * address, so a buyer address block would be empty lines.
     */
    public function test_a_receipt_omits_the_buyer_address(): void
    {
        $this->print($this->invoice('simplified'))->assertDontSee('Prince Sultan Road');
    }

    /**
     * BT-120. A zero in the VAT column with no reason beside it reads as an
     * arithmetic mistake rather than an exemption.
     */
    public function test_a_standard_names_the_exemption(): void
    {
        $invoice = $this->invoice('standard', line: [
            'tax_rate' => '0.00',
            'tax_amount' => '0.00',
            'exempt_code' => 'VATEX-SA-HEA',
            'exempt_reason' => 'Private healthcare to a Saudi citizen',
        ]);

        $this->print($invoice)
            ->assertSee('Private healthcare to a Saudi citizen')
            // And the authority's own Arabic for the code, from §11.2.4 of
            // the XML Implementation Standard.
            ->assertSee('الخدمات الصحية الخاصة للمواطنين', false);
    }

    /**
     * A credit note says which invoice it adjusts and why.
     */
    public function test_a_credit_note_names_its_origin(): void
    {
        $this->print($this->invoice('standard', [
            'document_type' => 'credit_note',
            'billing_ref' => 'ACME-100',
            'adjustment_reason' => 'Goods returned',
        ]))->assertSee('ACME-100')->assertSee('Goods returned')
            // Titled as what it is: it printed "Tax invoice".
            ->assertSee('Credit note')
            ->assertSee('إشعار دائن', false)
            ->assertDontSee('Tax invoice');
    }

    public function test_a_debit_note_says_so(): void
    {
        $this->print($this->invoice('standard', ['document_type' => 'debit_note', 'billing_ref' => 'ACME-100']))
            ->assertSee('Debit note')
            ->assertSee('إشعار مدين', false);
    }

    /**
     * [GL] §4.2.3: the date and the time of issue, on the Kingdom's clock as
     * the XML's IssueTime is.
     */
    public function test_the_issue_time_is_printed(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 10:15:30', 'UTC'));

        $this->print($this->invoice('simplified'))->assertSee('2026-03-01 13:15:30');
    }

    /**
     * BT-111. A foreign-currency invoice states its VAT in riyals too, at the
     * rate the XML converts with: 15.00 USD at 3.75 is 56.25 SAR.
     */
    public function test_foreign_currency_vat_in_riyals(): void
    {
        $this->print($this->invoice('standard', ['currency' => 'USD', 'exchange_rate' => '3.750000']))
            ->assertSee('VAT (SAR)')
            ->assertSee('56.25 SAR')
            ->assertSee('1 USD = 3.75 SAR');

        $this->print($this->invoice('standard', ['icv' => 2]))->assertDontSee('VAT (SAR)');
    }

    /**
     * BT-46, the buyer's other identifier, under its register's name.
     */
    public function test_the_buyer_identifier_is_named(): void
    {
        $this->print($this->invoice('standard', ['buyer_vat_number' => null, 'buyer_id' => '1010010000', 'buyer_id_scheme' => 'CRN']))
            ->assertSee('Commercial registration')
            ->assertSee('السجل التجاري', false)
            ->assertSee('1010010000');
    }

    /**
     * The seller's CR number, which the PDF printed and this page did not,
     * and the VAT numbers captioned in Arabic.
     */
    public function test_the_seller_cr_is_printed(): void
    {
        $this->acme->forceFill(['cr_number' => '1010101010'])->save();

        $this->print($this->invoice('standard'))
            ->assertSee('1010101010')
            ->assertSee('الرقم الضريبي', false);
    }

    /**
     * BT-146 is the item net price. A standard invoice priced with VAT in
     * prints the net 50.00, not the 57.50 the customer was quoted.
     */
    public function test_a_standard_prints_net_price(): void
    {
        $this->print($this->invoice('standard', ['prices_include_vat' => true], ['quantity' => '2.000', 'unit_price' => '50.00']))
            ->assertSee('50.00 SAR')
            ->assertDontSee('57.50')
            ->assertDontSee('Unit price (incl. VAT)');
    }

    /**
     * The unit, which is not required and is useless to omit.
     */
    public function test_a_line_names_its_unit(): void
    {
        $this->print($this->invoice('simplified', line: ['unit_code' => 'KGM']))->assertSee('kg / كغ');
    }

    /**
     * Including a line stored before the unit codes were corrected.
     *
     * printed() cannot name PCE, because PCE is not a code in either list - so
     * without normalising first the receipt printed the raw value back.
     */
    public function test_a_legacy_unit_is_named(): void
    {
        $this->print($this->invoice('simplified', line: ['unit_code' => 'PCE']))->assertSee('pc / قطعة')->assertDontSee('PCE');
    }

    /**
     * Priced with VAT in, the price column is the price the customer saw and
     * is headed as such, as on ZATCA's model simplified invoice ("Unit price
     * (including VAT)"). Priced without, it is the net price.
     */
    public function test_the_price_column_says_which(): void
    {
        $this->print($this->invoice('simplified', ['prices_include_vat' => true, 'subtotal' => '100.00', 'total' => '115.00']))
            ->assertSee('Unit price (incl. VAT)')
            ->assertSee('سعر الوحدة شامل ضريبة القيمة المضافة', false)
            ->assertSee('115.00');

        $this->print($this->invoice('simplified', ['icv' => 2]))
            ->assertDontSee('Unit price (incl. VAT)');
    }

    private function print(Invoice $invoice): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id.'/print')
            ->assertOk());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $line
     */
    private function invoice(string $type, array $overrides = [], array $line = []): Invoice
    {
        $attributes = array_merge([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-'.uniqid(),
            'type' => $type,
            'document_type' => 'invoice',
            'status' => 'issued',
            'issue_date' => '2026-03-01',
            'currency' => 'SAR',
            'buyer_name' => $type === 'simplified' ? 'Walk-in customer' : 'Rawafid Logistics',
            'buyer_vat_number' => $type === 'simplified' ? null : '399999999900003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            'icv' => 1,
            'hash' => base64_encode(hash('sha256', 'acme', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
            // A standard invoice is withheld until ZATCA stamps it, and the
            // withheld page prints the notice instead of the document.
            'cleared_xml' => $type === 'simplified' ? null : '<Invoice/>',
        ], $overrides);

        // Drafted with its line and then issued, in that order: an issued
        // invoice's lines are part of the document and cannot be added to.
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([...$attributes, 'status' => 'draft']));

        $invoice->lines()->create([
            'description' => 'Widget',
            'quantity' => '1.000',
            'unit_code' => 'H87',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
            ...$line,
        ]);

        $invoice->update(['status' => $attributes['status']]);

        return $invoice->fresh(['lines']);
    }
}
