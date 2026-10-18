<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Mail\InvoiceMail;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The PDF a buyer keeps, and sending it to them.
 */
class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    private const XML = '<?xml version="1.0" encoding="UTF-8"?><Invoice>signed</Invoice>';

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'name_ar' => 'شركة أكمي للتجارة',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    /**
     * PDF/A-3 with the signed XML inside: the page a person reads and the
     * document a machine reads, in one file.
     */
    public function test_the_pdf_carries_the_xml(): void
    {
        $invoice = $this->invoice('simplified');

        $pdf = app(InvoicePdf::class)->render($invoice, $this->acme);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/AFRelationship /Alternative', $pdf);
        $this->assertStringContainsString('ACME-1.xml', $pdf);
        $this->assertStringContainsString('pdfaid:part>3<', $pdf);
    }

    public function test_the_portal_downloads_it(): void
    {
        $invoice = $this->invoice('simplified');

        $response = $this->portal()->get(route('portal.invoices.pdf', $invoice->id))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('ACME-1.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_draft_has_no_pdf(): void
    {
        $invoice = $this->invoice('simplified', ['status' => 'draft', 'hash' => null, 'qr_code' => null, 'icv' => null, 'signed_xml' => null]);

        $this->portal()->get(route('portal.invoices.pdf', $invoice->id))
            ->assertRedirect(route('portal.invoices.show', $invoice->id))
            ->assertSessionHas('error');
    }

    /**
     * A standard invoice may not reach the buyer before ZATCA clears it.
     */
    public function test_an_uncleared_standard_is_withheld(): void
    {
        $invoice = $this->invoice('standard');

        $this->portal()->get(route('portal.invoices.pdf', $invoice->id))->assertSessionHas('error');

        $invoice->forceFill(['cleared_xml' => self::XML])->save();
        $this->portal()->get(route('portal.invoices.pdf', $invoice->id))->assertOk();
    }

    /**
     * ZATCA rejected it, so it is not a tax invoice to hand anyone.
     */
    public function test_a_rejected_invoice_is_withheld(): void
    {
        $invoice = $this->invoice('simplified', ['status' => 'rejected']);

        $this->portal()->get(route('portal.invoices.pdf', $invoice->id))->assertSessionHas('error');
    }

    public function test_another_company_cannot_have_it(): void
    {
        $invoice = $this->invoice('simplified');
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $outsider = User::factory()->create();
        $outsider->organizations()->attach($rival->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($outsider)->withSession(['portal_org_id' => $rival->id])
            ->get(route('portal.invoices.pdf', $invoice->id))
            ->assertNotFound();
    }

    public function test_it_is_emailed_with_the_pdf(): void
    {
        Mail::fake();
        $invoice = $this->invoice('simplified');

        $this->portal()->post(route('portal.invoices.send', $invoice->id), ['email' => 'ap@gulf.test'])
            ->assertSessionHas('success');

        Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail): bool {
            return $mail->hasTo('ap@gulf.test')
                && $mail->fileName === 'ACME-1.pdf'
                && str_starts_with($mail->pdf, '%PDF-');
        });
    }

    /**
     * The send form starts with the address of the customer with this VAT
     * number, when there is one.
     */
    public function test_the_customer_address_is_offered(): void
    {
        $invoice = $this->invoice('simplified', ['buyer_vat_number' => '310122393500003']);
        Customer::withoutTenantScope(fn () => Customer::query()->create([
            'org_id' => $this->acme->id,
            'name' => 'Gulf Supplies',
            'vat_number' => '310122393500003',
            'email' => 'ap@gulf.test',
        ]));

        $this->portal()->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('value="ap@gulf.test"', false);
    }

    /**
     * The mail server is not for reaching anyone in bulk: one address gets
     * at most ten invoices a day from one company.
     */
    public function test_sending_is_capped_per_day(): void
    {
        Mail::fake();
        $invoice = $this->invoice('simplified');

        for ($i = 0; $i < 10; $i++) {
            $this->portal()->post(route('portal.invoices.send', $invoice->id), ['email' => 'ap@gulf.test'])->assertSessionHas('success');
        }

        $this->portal()->post(route('portal.invoices.send', $invoice->id), ['email' => 'ap@gulf.test'])->assertSessionHas('error');

        Mail::assertSentCount(10);
    }

    public function test_a_viewer_cannot_send_it(): void
    {
        Mail::fake();
        $invoice = $this->invoice('simplified');
        $this->acme->users()->updateExistingPivot($this->member->id, ['role' => 'viewer']);

        $this->portal()->post(route('portal.invoices.send', $invoice->id), ['email' => 'ap@gulf.test'])->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_the_api_serves_it(): void
    {
        $invoice = $this->invoice('simplified');
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'member'])->fromUser($this->member);

        $response = $this->withToken($token)->get('/api/invoices/'.$invoice->id.'/pdf')->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_the_api_refuses_a_draft(): void
    {
        $invoice = $this->invoice('simplified', ['status' => 'draft', 'hash' => null, 'qr_code' => null, 'icv' => null, 'signed_xml' => null]);
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'member'])->fromUser($this->member);

        $this->withToken($token)->getJson('/api/invoices/'.$invoice->id.'/pdf')->assertStatus(409);
    }

    /**
     * A note is titled as one, and the time of issue is printed beside the
     * date, on the clock the XML's IssueTime uses.
     */
    public function test_the_page_names_note_and_time(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 10:15:30', 'UTC'));
        $invoice = $this->invoice('standard', ['document_type' => 'credit_note', 'billing_ref' => 'ACME-0', 'adjustment_reason' => 'Returned']);

        $html = $this->page($invoice);

        $this->assertStringContainsString('إشعار دائن', $html);
        $this->assertStringContainsString('2026-03-01 13:15:30', $html);
    }

    /**
     * BT-111 and the rate, on a foreign-currency invoice: 15.00 USD at 3.75.
     */
    public function test_the_page_states_vat_in_riyals(): void
    {
        $html = $this->page($this->invoice('standard', ['currency' => 'USD', 'exchange_rate' => '3.750000']));

        $this->assertStringContainsString('VAT (SAR)', $html);
        $this->assertStringContainsString('56.25 SAR', $html);
        $this->assertStringContainsString('1 USD = 3.75 SAR', $html);
    }

    /**
     * The buyer's other identifier, the authority's Arabic for an exemption
     * code, and the net unit price on a standard invoice priced with VAT in.
     */
    public function test_the_page_carries_standard_fields(): void
    {
        $html = $this->page($this->invoice(
            'standard',
            ['buyer_id' => '1010010000', 'buyer_id_scheme' => 'CRN', 'prices_include_vat' => true],
            ['exempt_code' => 'VATEX-SA-32', 'exempt_reason' => 'Export'],
        ));

        // Arabic first, as a Saudi invoice prints unless its organization
        // chose English first (PrintPreferences::arabicFirst).
        $this->assertStringContainsString('السجل التجاري / Commercial registration', $html);
        $this->assertStringContainsString('صادرات السلع من المملكة', $html);
        $this->assertStringNotContainsString('Unit price (incl. VAT)', $html);
    }

    private function page(Invoice $invoice): string
    {
        return view('countries.saudi-arabia.print.pdf', [
            'invoice' => $invoice,
            'organization' => $this->acme,
            'simplified' => $invoice->type === InvoiceType::Simplified,
            'qr' => null,
            'logo' => null,
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $line
     */
    private function invoice(string $type, array $overrides = [], array $line = []): Invoice
    {
        // A draft first, because an issued invoice refuses new lines; then
        // issued, as Submitter leaves it.
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-1',
            'type' => $type,
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting hour',
            'description_ar' => 'ساعة استشارية',
            'quantity' => '1',
            'unit_code' => 'HUR',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
            ...$line,
        ]);

        $invoice->forceFill(array_merge([
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Gulf Supplies',
            'buyer_name_ar' => 'مؤسسة الخليج',
            'buyer_address' => ['street' => 'Tahlia', 'building_number' => '1111', 'city' => 'Jeddah', 'postal_code' => '22222', 'country_code' => 'SA'],
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            'icv' => 1,
            'hash' => base64_encode(hash('sha256', 'acme', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
            'signed_xml' => self::XML,
        ], $overrides))->save();

        return $invoice->fresh(['lines']);
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
