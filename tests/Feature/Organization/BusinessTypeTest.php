<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Services\BusinessTypes;
use App\Domains\Organization\Services\SetupChecklist;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sector presets: what an organization says it is suggests its defaults -
 * and never refuses it anything.
 */
class BusinessTypeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'business-type-token';

    private Organization $pharmacy;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pharmacy = Organization::create(['name' => 'Al Dawaa', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->pharmacy->id, ['role' => 'admin', 'status' => 'active']);
        config(['peppol.countries.AE.enabled' => true]);
    }

    public function test_the_seeded_list_and_country_scope(): void
    {
        $types = app(BusinessTypes::class);

        $this->assertSame(
            ['retail', 'supermarket', 'pharmacy', 'clinic_hospital', 'school', 'wholesale', 'services', 'ecommerce', 'manufacturer', 'government_supplier', 'exporter', 'mixed'],
            array_map(fn ($type) => $type->key, $types->all()),
        );
        $this->assertCount(12, $types->offered(CompanyProfileRules::jurisdiction('SA')));

        // The UAE leaves consumer-only sales out and notes the rest.
        $uae = $types->offered(CompanyProfileRules::jurisdiction('AE'));
        $this->assertNotContains('retail', array_map(fn ($type) => $type->key, $uae));
        $this->assertNotContains('supermarket', array_map(fn ($type) => $type->key, $uae));
        $this->assertStringContainsString('section 6.2', (string) collect($uae)->firstWhere('key', 'pharmacy')->note);
        $this->assertNull(collect($uae)->firstWhere('key', 'wholesale')->note);
    }

    public function test_the_company_page_sets_the_type(): void
    {
        $this->portal()->get('/portal/company')->assertOk()->assertSee('name="business_type"', false)->assertSee('Pharmacy');
        $this->portal()->post('/portal/company/business-type', ['_token' => self::TOKEN, 'business_type' => 'pharmacy'])->assertSessionHas('success');

        $this->assertSame('pharmacy', $this->pharmacy->fresh()->business_type);

        $this->portal()->post('/portal/company/business-type', ['_token' => self::TOKEN, 'business_type' => 'nonsense'])->assertSessionHasErrors('business_type');
    }

    /**
     * A pharmacy prints receipts on an 80 mm roll and prices with VAT in
     * them - until the organization says otherwise, which wins.
     */
    public function test_the_organization_overrides_its_type(): void
    {
        $customizations = app(Customizations::class);
        $scope = Scope::organization($this->pharmacy->id);

        $this->assertSame(['a4', false], [$customizations->get('printing.paper', $scope), (bool) $customizations->get('invoices.prices_include_vat', $scope)]);

        $this->pharmacy->forceFill(['business_type' => 'pharmacy'])->save();
        $this->assertSame(['80mm', true], [$customizations->get('printing.paper', $scope), (bool) $customizations->get('invoices.prices_include_vat', $scope)]);

        $this->pharmacy->forceFill(['prices_include_vat' => false])->save();
        $this->assertFalse((bool) $customizations->get('invoices.prices_include_vat', $scope));
    }

    /**
     * The form starts where the type suggests, the shortcuts and setup steps
     * follow it, and a standard B2B invoice is still drafted: a default,
     * never a limit.
     */
    public function test_defaults_never_limit_a_feature(): void
    {
        $this->pharmacy->forceFill(['business_type' => 'pharmacy'])->save();

        $form = (string) $this->asRequest(fn () => $this->portal()->get('/portal/invoices/create')->assertOk())->getContent();
        $this->assertMatchesRegularExpression('#<option value="simplified"\s+selected>#', $form);
        $this->assertStringContainsString('Common for your business', $form);

        $this->asRequest(fn () => $this->portal()->get('/portal')->assertOk()->assertSee(route('portal.products.index'), false)->assertSee(__('Daily close')));
        $this->assertContains('printing', array_column(app(SetupChecklist::class)->steps($this->pharmacy->fresh()), 'key'));

        $this->asRequest(fn () => $this->portal()->post('/portal/invoices', [
            '_token' => self::TOKEN, 'invoice_number' => 'B2B-1', 'type' => 'standard', 'document_type' => 'invoice',
            'issue_date' => now()->toDateString(), 'currency' => 'SAR', 'buyer_name' => 'Nahdi Medical', 'buyer_name_ar' => 'النهدي الطبية',
            'buyer_vat_number' => '300000000000003',
            'buyer_address' => ['street' => 'Tahlia', 'building_number' => '1234', 'city' => 'Jeddah', 'district' => 'Al Rawdah', 'postal_code' => '23435', 'country_code' => 'SA'],
            'lines' => [['description' => 'Insulin pens, carton', 'description_ar' => 'أقلام إنسولين', 'quantity' => '10', 'unit_price' => '150', 'unit_code' => 'C62', 'tax_category' => 'Z', 'tax_rate' => '0', 'exempt_code' => 'VATEX-SA-35']],
        ])->assertSessionHasNoErrors());

        $this->assertSame('standard', Invoice::withoutTenantScope(fn () => Invoice::query()->sole())->type->value);
    }

    public function test_admin_edits_the_list(): void
    {
        $owner = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);
        $auditor = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'auditor']);
        $fields = ['label' => 'Bakery', 'label_ar' => 'مخبز', 'invoice_type' => 'simplified', 'prices_include_vat' => '1', 'payment_means' => '10',
            'buyer_fields' => 'minimal', 'paper' => '58mm', 'vat_hints' => '', 'dashboard_panels' => ['new_invoice'], 'setup_steps' => ['printing']];

        $this->actingAs($auditor)->get('/admin/settings/business-types')->assertOk()->assertSee('Pharmacy');
        $this->actingAs($auditor)->post('/admin/settings/business-types', ['key' => 'bakery', ...$fields])->assertForbidden();

        $this->actingAs($owner)->post('/admin/settings/business-types', ['key' => 'bakery', ...$fields])->assertSessionHas('success');
        $this->actingAs($owner)->post('/admin/settings/business-types/bakery', [...$fields, 'label' => 'Bakery and cafe', 'label_ar' => 'مخبز ومقهى'])->assertSessionHas('success');
        $this->actingAs($owner)->post('/admin/settings/business-types/bakery/move', ['direction' => 'up']);
        $this->actingAs($owner)->post('/admin/settings/business-types/retail/retire', ['retired' => '1'])->assertSessionHas('success');

        $types = app(BusinessTypes::class);
        $bakery = $types->find('bakery');
        $this->assertSame(['Bakery and cafe', 'مخبز ومقهى', '58mm', ['printing']], [$bakery->label, $bakery->labelAr, $bakery->paper, $bakery->setupSteps]);
        $this->assertSame(['bakery', 'mixed'], array_slice(array_map(fn ($type) => $type->key, $types->all()), -2));

        // Retired: no longer offered, kept by an organization that chose it.
        $offered = $types->offeredKeys(CompanyProfileRules::jurisdiction('SA'));
        $this->assertNotContains('retail', $offered);
        $this->assertContains('retail', $types->offeredKeys(CompanyProfileRules::jurisdiction('SA'), 'retail'));
    }

    /** A sale over the counter is paid in cash by default, whatever the B2B default. */
    public function test_a_counter_sale_starts_on_cash(): void
    {
        $this->pharmacy->forceFill(['business_type' => 'services'])->save();

        $form = (string) $this->asRequest(fn () => $this->portal()->withSession(['_old_input' => ['type' => 'simplified']])->get('/portal/invoices/create')->assertOk())->getContent();

        $this->assertMatchesRegularExpression('#<option value="10"\s+selected>#', $form);
        $this->assertStringContainsString('data-simplified-means="10"', $form);
        $this->assertStringContainsString('data-standard-means="30"', $form);
    }

    private function portal(): static
    {
        return $this->actingAs($this->admin)->withSession(['_token' => self::TOKEN]);
    }
}
