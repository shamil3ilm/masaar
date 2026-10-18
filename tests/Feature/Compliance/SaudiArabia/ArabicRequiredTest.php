<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\Product;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\LanguageGrace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A Saudi invoice is in Arabic, refused before it is drafted when it is not.
 *
 * ZATCA's E-Invoicing Detailed Guidelines (v2, May 2023): the invoice data
 * "must be in Arabic" (p60), and "any information contained in the human
 * readable form of the Tax Invoice must be in Arabic including notes" (p63).
 * Each line's description, any notes, and the buyer's name on a standard
 * invoice and its notes - on every way in: the API, the pipeline, its
 * batches and the portal. A saved product or customer fills in its own
 * Arabic name first; a platform admin's grace period turns the refusal into
 * a warning until a date.
 */
class ArabicRequiredTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'arabic-required-token';

    private Organization $acme;

    private string $jwt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي للتجارة', 'country' => 'SA', 'vat_number' => '300000000000003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $this->jwt = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_api_refuses_a_line_without_arabic(): void
    {
        $response = $this->api($this->standard(['lines' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100]]]))
            ->assertStatus(422);

        $message = $response->json('error.details')['lines.0.description_ar'][0] ?? '';
        $this->assertStringContainsString('Arabic description required (ZATCA: the invoice must be in Arabic)', $message);
        $this->assertStringContainsString('الوصف بالعربية مطلوب', $message);
        $this->assertSame(0, $this->invoices());
    }

    public function test_latin_text_is_not_arabic(): void
    {
        $this->api($this->standard(['lines' => [['description' => 'Consulting', 'description_ar' => 'Consulting', 'quantity' => 1, 'unit_price' => 100]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.description_ar'], 'error.details');
    }

    public function test_standard_invoice_needs_arabic_buyer(): void
    {
        $this->api($this->standard(['buyer_name_ar' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['buyer_name_ar'], 'error.details');
    }

    public function test_simplified_invoice_needs_no_arabic_buyer(): void
    {
        $this->api($this->simplified())->assertCreated();
    }

    public function test_notes_must_carry_arabic(): void
    {
        $this->api($this->simplified(['notes' => 'Deliver to gate 3']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes'], 'error.details');

        $this->api($this->simplified(['notes' => 'التسليم عند البوابة ٣ / Deliver to gate 3']))->assertCreated();
    }

    /**
     * Every free text the invoice prints is Arabic too (p63): the payment
     * terms, a charge's reason, a credit note's reason.
     */
    public function test_printed_free_text_must_be_arabic(): void
    {
        $this->api($this->simplified([
            'payment_terms' => 'Net 30',
            'charges' => [['reason' => 'Handling', 'reason_code' => 'HD', 'amount' => '10.00']],
        ]))->assertStatus(422)->assertJsonValidationErrors(['payment_terms', 'charges.0.reason'], 'error.details');

        $this->api($this->standard([
            'document_type' => 'credit_note', 'billing_ref' => 'INV-1', 'adjustment_reason' => 'Goods came back damaged',
        ]))->assertStatus(422)->assertJsonValidationErrors(['adjustment_reason'], 'error.details');

        $this->api($this->simplified([
            'payment_terms' => 'خلال ٣٠ يوماً / Net 30',
            'charges' => [['reason' => 'مناولة / Handling', 'reason_code' => 'HD', 'amount' => '10.00']],
        ]))->assertCreated();
    }

    /**
     * Coded texts carry their Arabic: a credit note reason ZATCA lists takes
     * the data dictionary's Arabic, a charge its code's, and a saved
     * customer's terms are taken with it.
     */
    public function test_coded_texts_carry_their_arabic(): void
    {
        $customer = Customer::withoutTenantScope(fn () => Customer::query()->create([
            'org_id' => $this->acme->id, 'name' => 'Beta Industries', 'name_ar' => 'شركة بيتا للصناعات', 'country_code' => 'SA',
            'payment_terms' => 'الدفع خلال ٣٠ يوماً',
        ]));

        $id = $this->api($this->standard([
            'document_type' => 'credit_note', 'billing_ref' => 'INV-1', 'customer_id' => $customer->id,
            'adjustment_reason' => 'In case of goods or services refund.',
            'charges' => [['reason' => 'Delivery', 'reason_code' => 'DL', 'amount' => '25.00']],
        ]))->assertCreated()
            ->assertJsonPath('data.invoice.adjustment_reason', 'In case of goods or services refund. / عند ترجيع السلع أو الخدمات')
            ->assertJsonPath('data.invoice.payment_terms', 'الدفع خلال ٣٠ يوماً')
            ->json('data.invoice.id');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->with('charges')->findOrFail($id));
        $this->assertSame('Delivery / رسوم التوصيل', $invoice->charges[0]->reason);
    }

    public function test_credit_note_needs_arabic_buyer(): void
    {
        $this->api($this->standard([
            'document_type' => 'credit_note', 'billing_ref' => 'INV-1', 'adjustment_reason' => 'Returned goods', 'buyer_name_ar' => '',
        ]))->assertStatus(422)->assertJsonValidationErrors(['buyer_name_ar'], 'error.details');
    }

    public function test_saved_product_fills_the_arabic(): void
    {
        $product = $this->product('CONS-1');

        $this->api($this->simplified(['lines' => [
            ['description' => 'Consulting', 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100],
            ['description' => 'Consulting', 'product_code' => 'CONS-1', 'quantity' => 1, 'unit_price' => 100],
        ]]))->assertCreated()
            ->assertJsonPath('data.invoice.lines.0.description_ar', 'خدمات استشارية')
            ->assertJsonPath('data.invoice.lines.1.description_ar', 'خدمات استشارية');
    }

    public function test_saved_customer_fills_the_arabic(): void
    {
        $customer = Customer::withoutTenantScope(fn () => Customer::query()->create([
            'org_id' => $this->acme->id, 'name' => 'Beta Industries', 'name_ar' => 'شركة بيتا للصناعات', 'country_code' => 'SA',
        ]));

        $this->api($this->standard(['buyer_name_ar' => null, 'customer_id' => $customer->id]))
            ->assertCreated()
            ->assertJsonPath('data.invoice.buyer_name_ar', 'شركة بيتا للصناعات');
    }

    public function test_another_organizations_product_is_refused(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $product = Product::withoutTenantScope(fn () => Product::query()->create([
            'org_id' => $rival->id, 'name' => 'Theirs', 'name_ar' => 'منتجهم', 'unit_code' => 'C62', 'unit_price' => 1, 'tax_rate' => 15,
        ]));

        $this->api($this->simplified(['lines' => [['description' => 'Theirs', 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.product_id'], 'error.details');
    }

    public function test_pipeline_and_batch_refuse_it(): void
    {
        $credential = $this->licence();
        $bare = [...$this->simplified(['lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 100]]]), 'auto_submit' => false];

        $this->withHeaders($credential)->postJson('/api/v1/pipeline/submit', [...$bare, 'org_id' => $this->acme->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.description_ar'], 'error.details');

        $batch = $this->withHeaders($credential)->postJson('/api/v1/pipeline/batch', [
            'org_id' => $this->acme->id,
            'invoices' => [[...$this->simplified(), 'invoice_number' => 'B-1', 'auto_submit' => false], [...$bare, 'invoice_number' => 'B-2']],
        ])->assertStatus(207);

        $this->assertSame([201, 422], array_column($batch->json('data.results'), 'http_status'));
        $this->assertArrayHasKey('lines.0.description_ar', $batch->json('data.results.1.error.details'));
    }

    public function test_portal_refuses_it(): void
    {
        $member = $this->member();

        $this->asRequest(fn (): TestResponse => $this->actingAs($member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/invoices', [
                '_token' => self::TOKEN, 'invoice_number' => 'P-1', 'type' => 'simplified', 'issue_date' => now()->toDateString(),
                'lines' => [['description' => 'Widget', 'description_ar' => '', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '15']],
            ]))
            ->assertSessionHasErrors(['lines.0.description_ar']);

        $this->assertSame(0, $this->invoices());
    }

    public function test_grace_warns_instead_of_refusing(): void
    {
        $this->grace(now()->addMonth(), 'Migrating its catalogue');

        $this->api($this->standard(['buyer_name_ar' => null, 'lines' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100]]]))
            ->assertCreated()
            ->assertJsonCount(2, 'data.warnings')
            ->assertJsonPath('data.warnings.0', fn (string $warning): bool => str_starts_with($warning, 'lines.0.description_ar: Arabic description required'));

        $this->withHeaders($this->licence())->postJson('/api/v1/pipeline/submit', [
            ...$this->simplified(['invoice_number' => 'PIPE-1', 'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 100]]]),
            'org_id' => $this->acme->id, 'auto_submit' => false,
        ])->assertCreated()->assertJsonCount(1, 'data.warnings');
    }

    public function test_grace_ends_on_its_own(): void
    {
        $this->grace(now()->subDay(), 'Over');

        $this->api($this->simplified(['lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 100]]]))->assertStatus(422);
    }

    public function test_portal_flags_it_during_grace(): void
    {
        $this->grace(now()->addWeek(), 'Migrating');
        $member = $this->member();

        $saved = $this->asRequest(fn (): TestResponse => $this->actingAs($member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/invoices', [
                '_token' => self::TOKEN, 'invoice_number' => 'P-2', 'type' => 'simplified', 'issue_date' => now()->toDateString(),
                'lines' => [['description' => 'Widget', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '15']],
            ]))
            ->assertSessionHas('warning');

        $this->asRequest(fn (): TestResponse => $this->actingAs($member)->get($saved->headers->get('Location')))
            ->assertOk()
            ->assertSee('data-missing-text', false)
            ->assertSee('Arabic text missing');
    }

    public function test_admin_grants_grace_with_audit(): void
    {
        $owner = User::factory()->create([
            'is_platform_admin' => true, 'platform_role' => 'owner', 'email' => 'ops@masaar.test',
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ]);
        $until = now()->addMonths(2)->toDateString();

        $this->asRequest(fn (): TestResponse => $this->actingAs($owner)
            ->withSession(['_token' => self::TOKEN])
            ->post('/admin/organizations/'.$this->acme->id.'/language-grace', ['_token' => self::TOKEN, 'until' => $until, 'reason' => 'Migrating its catalogue']))
            ->assertRedirect(route('admin.organization.detail', $this->acme->id))
            ->assertSessionHas('success');

        $grace = app(LanguageGrace::class)->describe($this->acme);
        $this->assertSame([$until, 'Migrating its catalogue', 'ops@masaar.test', true], [$grace['until'], $grace['reason'], $grace['by'], $grace['active']]);
        $this->assertNotNull(DB::table('language_graces')->where('org_id', $this->acme->id)->value('created_at'));

        $entry = AuditLog::where('action', 'admin.organizations.language-grace')->sole();
        $this->assertSame('Organization', $entry->entity_type);
        $this->assertSame((string) $this->acme->id, $entry->entity_id);
        $this->assertSame($until, $entry->new_values['language_grace_until']);

        $this->asRequest(fn (): TestResponse => $this->actingAs($owner)->get('/admin/organizations/'.$this->acme->id))
            ->assertOk()
            ->assertSee('Arabic text')
            ->assertSee('Until '.$until, false)
            ->assertSee('Migrating its catalogue');
    }

    public function test_admin_lists_missing_arabic_names(): void
    {
        $owner = User::factory()->create([
            'is_platform_admin' => true, 'platform_role' => 'auditor',
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ]);
        $this->product('CONS-1');
        Product::withoutTenantScope(fn () => Product::query()->create([
            'org_id' => $this->acme->id, 'name' => 'Widget', 'name_ar' => 'Widget', 'sku' => 'W-1', 'unit_code' => 'C62', 'unit_price' => 1, 'tax_rate' => 15,
        ]));
        Customer::withoutTenantScope(fn () => Customer::query()->create(['org_id' => $this->acme->id, 'name' => 'Gamma LLC', 'country_code' => 'SA']));

        $this->asRequest(fn (): TestResponse => $this->actingAs($owner)->get('/admin/organizations/'.$this->acme->id.'/missing-arabic'))
            ->assertOk()
            ->assertSee('Customers (1)')
            ->assertSee('Gamma LLC')
            ->assertSee('Products (1)')
            ->assertSee('W-1')
            ->assertDontSee('CONS-1');
    }

    public function test_product_form_requires_arabic_name(): void
    {
        $member = $this->member();

        $this->asRequest(fn (): TestResponse => $this->actingAs($member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/products', ['_token' => self::TOKEN, 'name' => 'Widget', 'unit_code' => 'C62', 'unit_price' => '10', 'tax_rate' => '15']))
            ->assertSessionHasErrors(['name_ar']);

        $this->asRequest(fn (): TestResponse => $this->actingAs($member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/customers', ['_token' => self::TOKEN, 'name' => 'Gamma LLC', 'country_code' => 'SA']))
            ->assertSessionHasErrors(['name_ar']);
    }

    public function test_company_page_requires_arabic_name(): void
    {
        $member = $this->member('admin');

        $this->asRequest(fn (): TestResponse => $this->actingAs($member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/company', [
                '_token' => self::TOKEN, 'name' => 'Acme Trading', 'name_ar' => '', 'vat_number' => '300000000000003',
                'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            ]))
            ->assertSessionHasErrors(['name_ar']);

        $this->assertSame('شركة أكمي للتجارة', $this->acme->fresh()->name_ar);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function api(array $payload): TestResponse
    {
        return $this->withToken($this->jwt)->postJson('/api/invoices', $payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function standard(array $overrides = []): array
    {
        return array_replace([
            'type' => 'standard', 'issue_date' => now()->toDateString(),
            'buyer_name' => 'Beta Industries', 'buyer_name_ar' => 'شركة بيتا للصناعات', 'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
            'lines' => [['description' => 'Consulting', 'description_ar' => 'خدمات استشارية', 'quantity' => 1, 'unit_price' => 100]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function simplified(array $overrides = []): array
    {
        return array_replace([
            'type' => 'simplified', 'issue_date' => now()->toDateString(),
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
        ], $overrides);
    }

    private function grace(\DateTimeInterface $until, string $reason): void
    {
        app(LanguageGrace::class)->set($this->acme, CarbonImmutable::parse($until)->startOfDay(), $reason, 'ops@masaar.test');
    }

    private function product(string $sku): Product
    {
        return Product::withoutTenantScope(fn () => Product::query()->create([
            'org_id' => $this->acme->id, 'name' => 'Consulting', 'name_ar' => 'خدمات استشارية', 'sku' => $sku, 'unit_code' => 'C62', 'unit_price' => 100, 'tax_rate' => 15,
        ]));
    }

    private function member(string $role = 'member'): User
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $member;
    }

    /**
     * @return array<string, string>
     */
    private function licence(): array
    {
        License::create([
            'org_id' => $this->acme->id, 'api_key' => 'cp_test_arabic', 'api_secret_hash' => License::hashSecret('secret-arabic'),
            'organization_name' => 'Acme', 'contact_email' => 'ops@masaar.test', 'environment' => 'sandbox', 'tier' => 'enterprise',
            'status' => 'active', 'scopes' => ['invoice.submit', 'compliance.status'],
        ]);

        return ['X-API-Key' => 'cp_test_arabic', 'X-API-Secret' => 'secret-arabic'];
    }

    private function invoices(): int
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $this->acme->id)->count());
    }
}
