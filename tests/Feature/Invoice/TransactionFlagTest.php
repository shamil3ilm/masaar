<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Presentation\PrintedTerms;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * KSA-2's transaction types - third party, nominal, export, summary and
 * self-billed - as a caller sends them.
 *
 * The invoice has had a column for each, and the document builder has read
 * them into the signed type name, since before anything accepted them: no
 * rule named them, so validation dropped them and every export an
 * integrator flagged was filed as a domestic sale. Each surface - the API,
 * the ERP pipeline and the portal - now keeps them, and the combinations
 * ZATCA refuses are refused before the invoice is drafted.
 */
class TransactionFlagTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL_TOKEN = 'flag-form-token';

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_the_api_keeps_every_flag(): void
    {
        $this->api($this->standard([
            'is_third_party' => true,
            'is_nominal' => true,
            'is_summary' => true,
        ]))->assertCreated();

        $this->assertFlags(['is_third_party' => true, 'is_nominal' => true, 'is_export' => false, 'is_summary' => true, 'is_self_billed' => false]);
    }

    public function test_an_export_is_kept(): void
    {
        $this->api($this->export())->assertCreated();

        $this->assertFlags(['is_third_party' => false, 'is_nominal' => false, 'is_export' => true, 'is_summary' => false, 'is_self_billed' => false]);
    }

    public function test_the_pipeline_keeps_the_flags(): void
    {
        $this->pipeline($this->export())->assertSuccessful();

        $this->assertFlags(['is_third_party' => false, 'is_nominal' => false, 'is_export' => true, 'is_summary' => false, 'is_self_billed' => false]);
    }

    /**
     * A form sends a ticked box as "1" and an unticked one not at all.
     */
    public function test_the_portal_keeps_the_flags(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->admin)
            ->withSession(['_token' => self::PORTAL_TOKEN])
            ->post('/portal/invoices', [
                '_token' => self::PORTAL_TOKEN,
                ...$this->base(),
                ...$this->standard(['is_self_billed' => '1']),
            ])->assertSessionHasNoErrors());

        $this->assertFlags(['is_third_party' => false, 'is_nominal' => false, 'is_export' => false, 'is_summary' => false, 'is_self_billed' => true]);
    }

    public function test_no_flag_is_a_plain_invoice(): void
    {
        $this->api($this->standard())->assertCreated();

        $this->assertFlags(['is_third_party' => false, 'is_nominal' => false, 'is_export' => false, 'is_summary' => false, 'is_self_billed' => false]);
    }

    public function test_an_export_is_not_self_billed(): void
    {
        $this->api($this->export(['is_self_billed' => true]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.is_self_billed.0', 'An export invoice cannot be self-billed (BR-KSA-07).');
    }

    public function test_simplified_cannot_be_self_billed(): void
    {
        $this->api(['is_self_billed' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.details.is_self_billed.0', 'A simplified invoice cannot be self-billed (BR-KSA-31).');
    }

    public function test_simplified_cannot_be_an_export(): void
    {
        $this->api(['is_export' => true, 'lines' => $this->export()['lines']])
            ->assertStatus(422)
            ->assertJsonPath('error.details.is_export.0', 'An export is invoiced on a standard tax invoice, not a simplified one (BR-KSA-31).');
    }

    /**
     * ZATCA ties no tax category or exemption code to an export: its SDK
     * accepts international transport (VATEX-SA-34-1 to 34-5) and standard,
     * exempt and out-of-scope lines beside the exported ones. Only what ZATCA
     * refuses is refused.
     */
    public function test_an_export_takes_what_zatca_takes(): void
    {
        $this->api($this->export(['invoice_number' => 'EXP-T', 'lines' => [
            ['description' => 'Freight to Houston', 'description_ar' => 'شحن إلى هيوستن', 'quantity' => 1, 'unit_price' => 900, 'tax_category' => 'Z', 'tax_rate' => 0, 'exempt_code' => 'VATEX-SA-34-1', 'exempt_reason' => 'International transport of goods'],
            ['description' => 'Handling in Dammam', 'description_ar' => 'مناولة في الدمام', 'quantity' => 1, 'unit_price' => 100],
        ]]))->assertCreated();
    }

    /** BR-KSA-46 (XML Implementation Standard, p57): no buyer VAT number on an export. */
    public function test_an_export_names_no_buyer_vat(): void
    {
        $this->api($this->export(['buyer_vat_number' => '399999999800003']))
            ->assertStatus(422)
            ->assertJsonPath('error.details.buyer_vat_number.0', 'An export invoice names no buyer VAT number (BR-KSA-46). Identify a buyer abroad by another identifier (buyer_id), such as OTH.');

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /**
     * A government buyer (KSA-2 position 9) through each surface, with the
     * contract BR-KSA-92 requires, shown on the invoice page and on paper.
     */
    public function test_a_government_buyer_through_each_surface(): void
    {
        $government = $this->standard(['is_government' => true, 'contract_reference' => 'MOF-2026-118']);

        $this->api([...$government, 'contract_reference' => null])
            ->assertStatus(422)
            ->assertJsonPath('error.details.contract_reference.0', 'A government (B2G) invoice names the contract it falls under (BR-KSA-92).');
        $this->pipeline([...$government, 'invoice_number' => 'INV-P'])->assertSuccessful();
        $this->api([...$government, 'invoice_number' => 'INV-A'])->assertCreated();
        $this->asRequest(fn () => $this->actingAs($this->admin)
            ->withSession(['_token' => self::PORTAL_TOKEN])
            ->post('/portal/invoices', ['_token' => self::PORTAL_TOKEN, ...$this->base(), ...$government, 'invoice_number' => 'INV-W', 'is_government' => '1'])
            ->assertSessionHasNoErrors());

        $invoices = Invoice::withoutTenantScope(fn () => Invoice::query()->orderBy('invoice_number')->get());
        $this->assertSame([true, true, true], $invoices->pluck('is_government')->all());

        $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.invoices.show', $invoices->first()->id))
            ->assertOk()->assertSee('Government (B2G)')->assertSee('MOF-2026-118');

        // Every paper prints the same terms (PrintedTerms): the page, the
        // PDF and the receipt.
        $printed = array_map(fn ($row): string => $row->label->en.'='.$row->value, PrintedTerms::of($invoices->first()));
        $this->assertContains('Transaction=Government (B2G) / جهة حكومية', $printed);
        $this->assertContains('Contract=MOF-2026-118', $printed);
    }

    public function test_a_simplified_summary_names_its_buyer(): void
    {
        $this->api(['is_summary' => true, 'buyer_name' => null])
            ->assertStatus(422)
            ->assertJsonPath('error.details.buyer_name.0', 'A simplified summary invoice names its buyer (BR-KSA-71).')
            ->assertJsonPath('error.details.supply_end_date.0', 'A simplified summary invoice states the period it covers: a supply date and a supply end date (BR-KSA-72).');
    }

    /**
     * @param  array<string, bool>  $expected
     */
    private function assertFlags(array $expected): void
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->sole());

        $this->assertSame($expected, $invoice->only(array_keys($expected)));
    }

    /**
     * Goods to a buyer abroad, zero-rated as an export.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function export(array $overrides = []): array
    {
        return $this->standard([
            'is_export' => true,
            'buyer_vat_number' => null,
            'buyer_id' => 'US1234567891',
            'buyer_id_scheme' => 'OTH',
            'buyer_address' => [
                'street' => 'Canadian Street',
                'building_number' => '12',
                'city' => 'Houston',
                'postal_code' => '77001',
                'country_code' => 'US',
            ],
            'lines' => [[
                'description' => 'Printing equipment',
                'description_ar' => 'معدات طباعة',
                'quantity' => 1,
                'unit_price' => 15000,
                'exempt_code' => 'VATEX-SA-32',
                'exempt_reason' => 'Export of goods',
            ]],
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function standard(array $overrides = []): array
    {
        return [
            'type' => 'standard',
            'buyer_name' => 'Beta Industries',
            'buyer_name_ar' => 'شركة بيتا للصناعات',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => [
                'street' => 'Olaya Street',
                'building_number' => '4321',
                'district' => 'Al Murooj',
                'city' => 'Riyadh',
                'postal_code' => '11564',
                'country_code' => 'SA',
            ],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function api(array $overrides): TestResponse
    {
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);

        return $this->withToken($token)->postJson('/api/invoices', [...$this->base(), ...$overrides]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pipeline(array $overrides): TestResponse
    {
        $secret = 'test-secret-acme';

        License::create([
            'org_id' => $this->acme->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret($secret),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@example.test',
            'environment' => 'sandbox',
            'tier' => 'professional',
            'status' => 'active',
            'scopes' => ['invoice.submit', 'compliance.status'],
        ]);

        return $this->withHeaders(['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => $secret])
            ->postJson('/api/v1/pipeline/submit', [
                ...$this->base(),
                'org_id' => $this->acme->id,
                'document_type' => 'invoice',
                'auto_submit' => false,
                ...$overrides,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function base(): array
    {
        return [
            'invoice_number' => 'INV-1',
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer',
            'buyer_name_ar' => 'المشتري',
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
        ];
    }
}
