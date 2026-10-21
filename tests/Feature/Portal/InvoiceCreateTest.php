<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Http\Requests\CreateInvoiceRequest;
use App\Domains\Invoice\Http\Requests\CreatePortalInvoiceRequest;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Writing an invoice from the portal.
 *
 * The engine could already do all of this - the drafter, the totals and the
 * validation rules are the ones the API and the ERP pipeline use - and there
 * was no screen for any of it, so a taxpayer with no POS integration could not
 * produce an invoice at all.
 *
 * What is new is the form, and a form sends things JSON does not: empty
 * strings for every untouched input, a repeater row waiting to be filled in,
 * and a buyer address that is six always-present inputs rather than an absent
 * key. Each of those turns a valid invoice into a validation error against a
 * field the taxpayer never touched, which is what most of this covers.
 */
class InvoiceCreateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'invoice-form-token';

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

    public function test_the_form_renders(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee('New invoice')
            ->assertSee('lines[0][description]', false));
    }

    /** Saved prices are in this currency; another one is converted, not copied. */
    public function test_the_form_knows_catalogue_currency(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee('data-catalogue-currency="SAR"', false));
    }

    public function test_a_draft_is_saved(): void
    {
        $response = $this->submit($this->simplified());

        $invoice = Invoice::withoutTenantScope(
            fn () => Invoice::where('invoice_number', 'PORTAL-1')->firstOrFail()
        );

        $response->assertRedirect(route('portal.invoices.show', $invoice->id));
        $response->assertSessionHas('success');

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($this->acme->id, $invoice->org_id);
        $this->assertCount(1, $invoice->lines);

        // A draft is not a chain member, and saying so is half the point of
        // keeping issuing a separate decision.
        $this->assertNull($invoice->icv);
        $this->assertNull($invoice->hash);
    }

    /**
     * The totals come from InvoiceTotals, not from the form.
     *
     * Nothing in the posted data says what anything costs in total, and it
     * must not: the document's VAT is not the sum of its line VAT once a
     * document discount is apportioned, and a browser arriving at its own
     * figure would disagree with the signed document.
     */
    public function test_the_totals_are_calculated(): void
    {
        $this->submit($this->simplified([
            'lines' => [
                ['description' => 'Widget', 'description_ar' => 'أداة', 'quantity' => '2', 'unit_price' => '100', 'tax_rate' => '15', 'unit_code' => 'PCE'],
            ],
        ]));

        $invoice = Invoice::withoutTenantScope(
            fn () => Invoice::where('invoice_number', 'PORTAL-1')->firstOrFail()
        );

        $this->assertSame('200.00', (string) $invoice->subtotal);
        $this->assertSame('30.00', (string) $invoice->tax_amount);
        $this->assertSame('230.00', (string) $invoice->total);
    }

    /**
     * Prices typed as the customer pays them: the total is what was typed,
     * and the net is backed out of it.
     */
    public function test_inclusive_prices_are_kept(): void
    {
        $this->submit($this->simplified([
            'prices_include_vat' => '1',
            'lines' => [
                ['description' => 'Widget', 'description_ar' => 'أداة', 'quantity' => '3', 'unit_price' => '10.00', 'tax_rate' => '15', 'unit_code' => 'PCE'],
            ],
        ]));

        $invoice = Invoice::withoutTenantScope(
            fn () => Invoice::with('lines')->where('invoice_number', 'PORTAL-1')->firstOrFail()
        );

        $this->assertTrue($invoice->prices_include_vat);
        $this->assertSame('30.00', (string) $invoice->total);
        $this->assertSame('26.09', (string) $invoice->subtotal);
        $this->assertSame('10.00', $invoice->lines[0]->priceAsEntered(true));
    }

    /**
     * The empty row the repeater is holding is not an invoice line.
     *
     * Pressing "Add a line" and then not using it posts a row of empty
     * strings. Submitted as-is it fails lines.*.description required, so
     * adding a row and changing your mind would refuse the whole invoice.
     */
    public function test_an_unused_row_is_dropped(): void
    {
        $response = $this->submit($this->simplified([
            'lines' => [
                ['description' => 'Widget', 'description_ar' => 'أداة', 'quantity' => '1', 'unit_price' => '50', 'tax_rate' => '15', 'unit_code' => 'PCE'],
                ['description' => '', 'quantity' => '', 'unit_price' => '', 'tax_rate' => '15', 'unit_code' => 'PCE'],
            ],
        ]));

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::withoutTenantScope(
            fn () => Invoice::where('invoice_number', 'PORTAL-1')->firstOrFail()
        );

        $this->assertCount(1, $invoice->lines);
    }

    /**
     * A simplified invoice has no buyer address, and six empty inputs are not
     * one. Left as empty strings, country_code fails size:2 - a rule about a
     * field the taxpayer correctly left alone.
     */
    public function test_an_empty_address_becomes_absent(): void
    {
        $response = $this->submit($this->simplified([
            'buyer_address' => [
                'street' => '', 'building_number' => '', 'city' => '',
                'district' => '', 'postal_code' => '', 'country_code' => '',
            ],
        ]));

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::withoutTenantScope(
            fn () => Invoice::where('invoice_number', 'PORTAL-1')->firstOrFail()
        );

        $this->assertNull($invoice->buyer_address);
    }

    /**
     * And the inherited rules still refuse what ZATCA refuses.
     *
     * A standard invoice needs the buyer's VAT number and address (BT-46,
     * BT-50). The portal must not be the lenient way in.
     */
    public function test_a_standard_invoice_needs_more(): void
    {
        $response = $this->submit($this->simplified([
            'type' => 'standard',
            'buyer_vat_number' => '',
        ]));

        $response->assertSessionHasErrors('buyer_vat_number');

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::count()));
    }

    /** Each refusal reads as words and names its field, once. */
    public function test_buyer_errors_name_their_field(): void
    {
        $this->submit($this->simplified([
            'type' => 'standard',
            'buyer_vat_number' => '',
            'buyer_id_scheme' => 'OTH',
            'buyer_id' => 'AE-TRN-100123456700003',
        ]))->assertSessionHasErrors([
            'buyer_address.street' => 'Buyer street is required for B2B (standard) invoices.',
            'buyer_address.city' => 'Buyer city is required for B2B (standard) invoices.',
            'buyer_id' => 'The identifier is letters and digits only, with no spaces. A citizen\'s national ID is ten digits starting with 1.',
        ]);

        $this->asRequest(fn () => $this->actingAs($this->member)->get('/portal/invoices/create'))
            ->assertOk()
            ->assertDontSee('buyer address.street')
            ->assertSee('data-field-error', false);
    }

    public function test_a_message_is_listed_once(): void
    {
        $this->submit($this->simplified(['type' => 'standard', 'buyer_vat_number' => '', 'buyer_id' => '']));

        $page = $this->asRequest(fn () => $this->actingAs($this->member)->get('/portal/invoices/create'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($page, '<li>'.e(__('A standard invoice needs the buyer VAT number or another buyer identifier.')).'</li>'));
    }

    /**
     * The Arabic fields reach the database.
     *
     * A Saudi tax invoice must be issued in Arabic, and these are where it
     * comes from: the buyer name and the line descriptions. The seller's own
     * Arabic name lives on the organization, not here.
     */
    public function test_the_arabic_fields_are_saved(): void
    {
        $response = $this->submit($this->simplified([
            'buyer_name_ar' => 'مشتري',
            'lines' => [
                [
                    'description' => 'Consulting',
                    'description_ar' => 'استشارات',
                    'quantity' => '1',
                    'unit_price' => '100',
                    'tax_rate' => '15',
                    'unit_code' => 'PCE',
                ],
            ],
        ]));

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::withoutTenantScope(
            fn () => Invoice::with('lines')->where('invoice_number', 'PORTAL-1')->firstOrFail()
        );

        $this->assertSame('مشتري', $invoice->buyer_name_ar);
        $this->assertSame('استشارات', $invoice->lines->first()?->description_ar);
    }

    /**
     * And they stay optional, because the columns are new and nothing has
     * been backfilled. InvoiceValidator warns instead.
     */
    public function test_arabic_is_not_required(): void
    {
        $this->submit($this->simplified())->assertSessionHasNoErrors();
    }

    /**
     * The portal request adds no rules of its own.
     *
     * It overrides prepareForValidation and nothing else, so a ZATCA rule
     * written once reaches the API, the pipeline and this form together. If
     * these ever diverge, one surface is quietly accepting what another
     * refuses.
     */
    public function test_the_portal_adds_no_rules(): void
    {
        $portal = array_keys((new CreatePortalInvoiceRequest)->rules());
        $api = array_keys((new CreateInvoiceRequest)->rules());

        sort($portal);
        sort($api);

        $this->assertSame($api, $portal,
            'CreatePortalInvoiceRequest no longer validates the same fields as '
            .'CreateInvoiceRequest, so the browser and the API disagree about what a '
            .'valid invoice is.');
    }

    /**
     * The form's running figures are asked for as the page leaves, so a
     * preview can land between the refusal and the form drawn again. It
     * must leave the errors and what was typed for that form.
     */
    public function test_a_preview_keeps_the_errors(): void
    {
        $this->submit($this->simplified(['type' => 'standard', 'buyer_name' => 'Typed buyer']))->assertSessionHasErrors();

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->withSession(['_token' => self::TOKEN])
            ->post(route('portal.invoices.preview'), ['_token' => self::TOKEN, 'lines' => []])
            ->assertOk());

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee('value="Typed buyer"', false)
            ->assertSee('<ul class="list-disc', false));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function simplified(array $overrides = []): array
    {
        return array_merge([
            '_token' => self::TOKEN,
            'invoice_number' => 'PORTAL-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'supply_date' => '',
            'currency' => 'SAR',
            'exchange_rate' => '',
            'billing_ref' => '',
            'adjustment_reason' => '',
            'buyer_name' => 'Walk-in customer',
            'buyer_name_ar' => 'عميل نقدي',
            'buyer_vat_number' => '',
            'discount_amount' => '',
            'notes' => '',
            'lines' => [
                ['description' => 'Widget', 'description_ar' => 'أداة', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '15', 'unit_code' => 'PCE'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(array $payload): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($this->member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/invoices', $payload));
    }
}
