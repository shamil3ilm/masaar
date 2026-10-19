<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Countries\SaudiArabia\Module as SaudiModule;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;
use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Enums\TaxCategory;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuanceNotifier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Services\SetupChecklist;
use App\Domains\Pipeline\Services\PipelineService;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Each country stays out of the other's way. The tests for a country's own
 * module use only that country's organizations; every test that brings in
 * the other country as a control is here, in one of two directions:
 *
 * - A Saudi organization, met by the UAE's code: refused by the UAE's API,
 *   form and screens, given no UAE deadline, document or field, and shown
 *   its own pages, rules and payloads as they were.
 * - A UAE organization, met by Saudi Arabia's code: ZATCA's issuer and the
 *   Saudi invoice form refuse it, so it never takes a counter, hash or
 *   signature.
 *
 * Each test is the one the file it names held, its assertions unchanged;
 * its name now says which side of the line it stands on.
 */
class JurisdictionIsolationTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private const TOKEN = 'jurisdiction-isolation-token';

    private const INBOUND_SECRET = 'inbound-secret';

    private const INBOUND_XML = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
        .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
        .'<cbc:CustomizationID>urn:peppol:pint:billing-1@ae-1</cbc:CustomizationID>'
        .'<cbc:ProfileID>urn:peppol:bis:billing</cbc:ProfileID>'
        .'<cbc:ID>SUP-001</cbc:ID></Invoice>';

    /*
    |--------------------------------------------------------------------------
    | A Saudi organization, met by the UAE's code
    |--------------------------------------------------------------------------
    */

    /** /api/compliance/ae is for a UAE organization (UaeApiTest). */
    public function test_saudi_is_refused_uae_api(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $saudi = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->api($saudi)->getJson('/api/compliance/ae/documents')->assertForbidden();
        $this->api($saudi)->postJson('/api/compliance/ae/invoices', $this->apiPayload())->assertForbidden();
    }

    /** The pipeline checks it by the Saudi rules while the UAE is on (UaePipelineTest). */
    public function test_saudi_pipeline_keeps_saudi_rules(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $saudi = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->partner($saudi)->postJson('/api/v1/pipeline/submit', [
            'org_id' => $saudi->id,
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'lines' => [['description' => 'Widget', 'quantity' => 1, 'unit_price' => 100]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.exchange_rate.0', 'Exchange rate to SAR is required for foreign-currency invoices (BR-53).')
            ->assertJsonMissingPath('error.details.buyer_endpoint')
            ->assertJsonMissingPath('error.details.buyer_subdivision');
    }

    /** No e-invoice, no deadline of the UAE's, and no new warning (UaeIssueDeadlineTest). */
    public function test_saudi_issue_has_no_uae_deadline(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $saudi = Organization::create([
            'name' => 'Acme', 'country' => 'SA', 'vat_number' => '300000000000003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
        $this->travelTo('2026-10-30 12:00:00');

        $result = app(PipelineService::class)->submitInvoice([
            'invoice_number' => 'INV-SA-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => '2026-10-30',
            'supply_date' => '2026-09-01',
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'auto_submit' => false,
            'lines' => [['description' => 'Widget', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 15, 'unit_code' => 'PCE']],
        ], $saudi->id);

        $this->assertSame('issued', $result['status'], implode("\n", $result['errors']));
        $this->assertSame([], $result['warnings']);
        $this->assertSame(0, PeppolDocument::query()->count());
    }

    /**
     * A Saudi profile is not a Peppol participant, whatever its settings say
     * (InboundTest).
     */
    public function test_saudi_profile_does_not_receive(): void
    {
        $this->app->instance(AspGateway::class, new FakeAspGateway(self::INBOUND_SECRET));
        $organization = Organization::create(['name' => 'Riyadh Co', 'country' => 'SA']);
        ComplianceProfile::withoutTenantScope(fn () => ComplianceProfile::create([
            'org_id' => $organization->id,
            'jurisdiction' => 'SA',
            'engine' => 'fatoora',
            'status' => 'active',
            'settings' => ['endpoint_id' => '0235:1000000002'],
        ]));

        $this->deliver('msg-in-1', '0235:1000000002')->assertNotFound();
    }

    /**
     * A Saudi invoice is refused the UAE's categories before it is drafted,
     * not by ZATCA after it is issued (PeppolFoundationTest).
     */
    public function test_saudi_invoice_refuses_uae_categories(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $user = User::factory()->create(['email' => 'biller@masaar.test']);
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'biller@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');

        foreach ([PintTaxCategory::ReverseCharge, PintTaxCategory::MarginScheme] as $category) {
            $this->withToken($token)
                ->postJson('/api/invoices', [
                    'type' => 'simplified',
                    'issue_date' => now()->toDateString(),
                    'lines' => [[
                        'description' => 'Item',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'tax_category' => $category->value,
                    ]],
                ])
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['details' => ['lines.0.tax_category']]]);
        }

        $this->assertSame(['S', 'Z', 'E', 'O'], array_column(TaxCategory::cases(), 'value'));
    }

    /**
     * The Saudi shapes, unchanged: four-digit building, five-digit postal
     * code, a 3...3 VAT number (UaeSellerDetailsTest).
     */
    public function test_saudi_seller_rules_are_unchanged(): void
    {
        $saudi = [
            'name' => 'Acme', 'name_ar' => 'شركة أكمي', 'vat_number' => '300000000000003', 'street' => 'King Fahd Road', 'building_number' => '1234',
            'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ];
        $errors = fn (array $data): array => Validator::make($data, CompanyProfileRules::rules('SA', complete: true), CompanyProfileRules::messages('SA'))->errors()->toArray();

        $this->assertSame([], $errors($saudi));
        $refused = $errors(array_merge($saudi, ['building_number' => '12', 'postal_code' => '1234', 'vat_number' => '100123456700003']));

        $this->assertSame(['The building number is 4 digits.'], $refused['building_number']);
        $this->assertSame(['The postal code is 5 digits.'], $refused['postal_code']);
        $this->assertSame(['A Saudi VAT number is 15 digits that start and end with 3.'], $refused['vat_number']);
        $this->assertArrayNotHasKey('emirate', CompanyProfileRules::rules('SA', complete: true));
    }

    /**
     * Saudi's invoice.issued has none of the UAE's extra fields: the golden
     * masters pin its bytes (UaeWebhookTest).
     */
    public function test_saudi_payload_keeps_its_shape(): void
    {
        Queue::fake();
        $sent = [];
        $webhooks = Mockery::mock(WebhookService::class);
        $webhooks->shouldReceive('dispatch')->andReturnUsing(function (string $org, string $event, array $payload) use (&$sent): void {
            $sent[] = ['event' => $event, 'payload' => $payload];
        });
        $this->app->instance(WebhookService::class, $webhooks);
        $invoice = $this->draft($this->uaeOrganization());

        app(IssuanceNotifier::class)->issued($invoice);

        $this->assertSame(['invoice_id', 'invoice_number', 'status', 'type', 'total', 'currency'], array_keys($sent[0]['payload']));
    }

    /** The Saudi company page asks the Saudi details (UaeCompanyTest). */
    public function test_saudi_company_page_is_unchanged(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->asRequest(fn () => $this->actingAs($this->member($acme, 'admin'))
            ->withSession(['portal_org_id' => $acme->id])
            ->get('/portal/company')
            ->assertOk()
            ->assertSee('National address')
            ->assertSee('name="vat_number"', false)
            ->assertSee('15 digits, starting and ending with 3.')
            ->assertDontSee('name="endpoint_id"', false)
            ->assertDontSee('name="emirate"', false));
    }

    /** A Saudi customer keeps no Peppol endpoint or emirate (UaeCustomerTest). */
    public function test_saudi_customers_are_unchanged(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $saudi = $this->member($acme, 'member');

        $this->actingAs($saudi)->withSession(['portal_org_id' => $acme->id])
            ->post('/portal/customers', [
                'name' => 'Gulf Supplies', 'name_ar' => 'الخليج للتوريدات', 'vat_number' => '310122393500003', 'city' => 'Riyadh', 'postal_code' => '12345',
                'country_code' => 'SA', 'peppol_endpoint' => 'anything', 'subdivision' => 'anything',
            ])
            ->assertSessionHasNoErrors();

        $customer = Customer::withoutTenantScope(fn () => Customer::query()->sole());
        $this->assertNull($customer->peppol_endpoint);
        $this->assertNull($customer->subdivision);

        $this->asRequest(fn () => $this->actingAs($saudi)->withSession(['portal_org_id' => $acme->id])
            ->get('/portal/customers/new')
            ->assertOk()
            ->assertDontSee('name="peppol_endpoint"', false)
            ->assertSee('value="SA"', false));
    }

    /** "New invoice" is the Saudi form (UaeInvoiceFormTest). */
    public function test_saudi_invoice_form_is_unchanged(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->asRequest(fn () => $this->actingAs($this->member($acme, 'admin'))
            ->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee(route('portal.invoices.store'), false)
            ->assertSee('value="debit_note"', false)
            ->assertSee('value="simplified"', false)
            ->assertSee('lines[0][description_ar]', false)
            ->assertDontSee('name="buyer_endpoint_choice"', false));
    }

    /** The UAE form's route does not exist for it (UaeInvoiceFormTest). */
    public function test_saudi_cannot_post_uae_form(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->asRequest(fn () => $this->actingAs($this->member($acme, 'admin'))
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/invoices/uae', [...$this->uaeForm(), '_token' => self::TOKEN])
            ->assertNotFound());
    }

    /** Its setup steps include ZATCA's, never the UAE's (UaeSetupTest). */
    public function test_saudi_setup_steps_are_unchanged(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $admin = $this->member($acme, 'admin');

        $this->asRequest(fn () => $this->actingAs($admin)->get('/portal')->assertOk())
            ->assertSee('Connect to ZATCA')
            ->assertDontSee('Complete your UAE seller details');
        $this->assertSame(
            ['company', 'approved', 'zatca', 'numbering', 'catalogue', 'invoice'],
            array_column(app(SetupChecklist::class)->steps($acme), 'key'),
        );
    }

    /** /portal/received is a UAE screen (ReceivedInvoicesTest). */
    public function test_saudi_has_no_received_invoices(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->asRequest(fn () => $this->actingAs($this->member($acme, 'member'))->get('/portal/received')->assertNotFound());
    }

    /** Its navigation links ZATCA onboarding and not received invoices (ReceivedInvoicesTest). */
    public function test_saudi_navigation_links_zatca_only(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->asRequest(fn () => $this->actingAs($this->member($acme, 'admin'))
            ->withSession(['portal_org_id' => $acme->id])
            ->get('/portal/invoices')
            ->assertDontSee(route('portal.received.index'), false)
            ->assertSee(route('portal.zatca.show'), false));
    }

    /** Its dashboard counts ZATCA submissions, not e-invoices (UaeDashboardTest). */
    public function test_saudi_dashboard_is_unchanged(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->asRequest(fn () => $this->actingAs($this->member($acme, 'member'))
            ->get('/portal')
            ->assertOk()
            ->assertViewMissing('einvoices')
            ->assertViewHas('certificate', null)
            ->assertSee('Your ZATCA compliance overview')
            ->assertSee('Recent submissions')
            ->assertSee('Activity by user (7 days)')
            ->assertDontSee('Recent e-invoices'));
    }

    /** Its portal is the ZATCA portal (UaeTenantDashboardTest). */
    public function test_saudi_tagline_is_unchanged(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->asRequest(fn () => $this->actingAs($this->member($acme, 'member'))
            ->get('/portal')
            ->assertOk()
            ->assertSee('ZATCA Portal')
            ->assertDontSee('UAE E-invoicing Portal'));
    }

    /*
    |--------------------------------------------------------------------------
    | A UAE organization, met by Saudi Arabia's code
    |--------------------------------------------------------------------------
    */

    /** ZATCA's issuer refuses it outright, before any counter (UaeRoutingTest). */
    public function test_zatca_issuing_refuses_uae_organization(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $organization = $this->uaeOrganization();
        $invoice = $this->draft($organization);

        $outcome = app(InvoiceIssuer::class)->issue($invoice, $organization, file: false);

        $this->assertFalse($outcome->issued);
        $this->assertStringContainsString('ZATCA issues Saudi invoices only', $outcome->errors[0]);
        $this->assertSame('draft', Invoice::query()->findOrFail($invoice->id)->status->value);
        $this->assertNull(Invoice::query()->findOrFail($invoice->id)->icv);
    }

    /** The pipeline no longer checks it by the Saudi rules (UaePipelineTest). */
    public function test_uae_pipeline_skips_saudi_rules(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization();

        $details = $this->partner($gulf)->postJson('/api/v1/pipeline/submit', [
            'org_id' => $gulf->id,
            'type' => 'standard',
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
            'lines' => [['item_name' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_category' => 'S']],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.exchange_rate.0', 'An invoice in another currency needs its exchange rate to AED.')
            ->json('error.details');

        $this->assertArrayNotHasKey('buyer_address.building_number', $details);
        $this->assertArrayNotHasKey('lines.0.description', $details);
        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /** The Saudi form's route drafts nothing for it (UaeInvoiceFormTest). */
    public function test_saudi_route_refuses_uae_organization(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $gulf = $this->uaeOrganization();

        $this->asRequest(fn () => $this->actingAs($this->member($gulf, 'admin'))->withSession(['portal_org_id' => $gulf->id, '_token' => self::TOKEN])
            ->post('/portal/invoices', [
                '_token' => self::TOKEN,
                'type' => 'standard', 'document_type' => 'invoice', 'issue_date' => now()->toDateString(), 'currency' => 'SAR',
                'buyer_name' => 'Noor', 'buyer_vat_number' => '300000000000003',
                'buyer_address' => ['street' => 'X', 'building_number' => '1234', 'district' => 'Y', 'city' => 'Z', 'postal_code' => '12345', 'country_code' => 'SA'],
                'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '15']],
            ])
            ->assertRedirect(route('portal.invoices.create'))
            ->assertSessionHas('error'));

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /**
     * Billed by a Saudi seller - the platform's own invoice for a plan - its
     * TRN is not a Saudi VAT number (BR-KSA-44), so it is another ID, and it
     * has no national address to check (BillingTest).
     */
    public function test_uae_buyer_billed_as_other(): void
    {
        $gulf = $this->uaeOrganization();
        $gulf->forceFill(['vat_number' => '100123456700003', 'building_number' => null, 'postal_code' => null])->save();

        $this->assertSame(
            ['buyer_vat_number' => null, 'buyer_id' => '100123456700003', 'buyer_id_scheme' => 'OTH'],
            app(SaudiModule::class)->invoiceRules()->billedBuyer($gulf),
        );
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    /** An ERP with a licence for the organization, on the partner API. */
    private function partner(Organization $organization): static
    {
        $issued = License::createWithCredentials([
            'org_id' => $organization->id,
            'organization_name' => $organization->name,
            'contact_email' => 'erp@isolation.test',
            'tier' => 'professional',
        ]);

        return $this->withHeaders(['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']]);
    }

    private function api(Organization $organization, string $role = 'admin'): static
    {
        $user = $this->member($organization, $role);

        return $this->withToken(JWTAuth::claims(['org_id' => $organization->id, 'role' => $role])->fromUser($user));
    }

    /**
     * An unnumbered UAE draft, as the API drafts one.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function draft(Organization $organization, array $attributes = []): Invoice
    {
        $invoice = $this->uaeInvoice($organization, ['invoice_number' => null, ...$attributes]);
        Invoice::query()->whereKey($invoice->id)->update([
            'status' => 'draft', 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00',
        ]);

        return Invoice::query()->findOrFail($invoice->id);
    }

    /** A signed delivery of INBOUND_XML from the provider. */
    private function deliver(string $messageId, string $receiver): TestResponse
    {
        $json = (string) json_encode([
            'message_id' => $messageId,
            'document' => base64_encode(self::INBOUND_XML),
            'sender' => '0235:1000000001',
            'receiver' => $receiver,
        ]);
        $timestamp = (string) time();

        return $this->call('POST', '/api/peppol/webhooks/inbound', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => (new WebhookSignature(self::INBOUND_SECRET))->sign($timestamp, $json),
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        ], $json);
    }

    /**
     * A UAE invoice as POST /api/compliance/ae/invoices takes it.
     *
     * @return array<string, mixed>
     */
    private function apiPayload(): array
    {
        return [
            'issue_date' => '2026-10-10',
            'due_date' => '2026-11-09',
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'lines' => [[
                'item_name' => 'Consulting',
                'description' => 'Consulting services, October',
                'quantity' => 2,
                'unit_code' => 'DAY',
                'unit_price' => 500,
                'tax_category' => 'S',
                'tax_rate' => 5,
            ]],
        ];
    }

    /**
     * The portal's UAE invoice form as a browser sends it: every untouched
     * field an empty string.
     *
     * @return array<string, mixed>
     */
    private function uaeForm(): array
    {
        return [
            'invoice_number' => '',
            'document_type' => 'invoice',
            'document_category' => 'tax',
            'issue_date' => now()->toDateString(),
            'supply_date' => '',
            'supply_end_date' => '',
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'AED',
            'exchange_rate' => '',
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_name_ar' => '',
            'buyer_vat_number' => '100765432100003',
            'buyer_endpoint_choice' => 'own',
            'buyer_endpoint' => '0235:1007654321',
            'buyer_address' => ['street' => 'Khalifa Street', 'building_number' => '', 'city' => 'Abu Dhabi', 'postal_code' => '', 'country_code' => 'AE'],
            'buyer_subdivision' => 'AUH',
            'buyer_legal_registration' => ['type' => '', 'id' => '', 'authority' => ''],
            'beneficiary_id' => '',
            'principal_id' => '',
            'delivery_address' => ['street' => '', 'city' => '', 'subdivision' => '', 'postal_code' => '', 'country_code' => ''],
            'lines' => [[
                'item_name' => 'Consulting',
                'description' => 'Consulting services, October',
                'quantity' => '2',
                'unit_code' => 'DAY',
                'unit_price' => '500',
                'tax_category' => 'S',
                'tax_rate' => '5',
                'exempt_code' => '',
                'goods_type' => '',
                'item_id' => '',
            ]],
            'billing_ref' => '',
            'credit_reason' => '',
            'discount_amount' => '',
            'notes' => '',
        ];
    }
}
