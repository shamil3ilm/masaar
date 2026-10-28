<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\Product;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A product's VAT treatment, and a customer's citizen zero rating, as the
 * defaults an invoice line starts with - still changed line by line.
 */
class CatalogueVatTreatmentTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'catalogue-vat-token';

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Al Shifa Clinic', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_a_product_keeps_its_vat_treatment(): void
    {
        $this->portal('/portal/products', [
            '_token' => self::TOKEN, 'name' => 'Insulin pen', 'name_ar' => 'قلم إنسولين', 'sku' => 'INS-1',
            'unit_code' => 'C62', 'unit_price' => '189.90', 'tax_rate' => '15', 'tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-35',
        ])->assertSessionHasNoErrors();

        $product = Product::acrossTenants()->sole();

        // Zero-rated, so its rate is zero whatever was typed.
        $this->assertSame(['Z', 'VATEX-SA-35', '0.00'], [$product->tax_category, $product->exempt_code, (string) $product->tax_rate]);
        $this->assertSame(['Z', 'VATEX-SA-35', '0'], [$product->lineFields()['tax_category'], $product->lineFields()['exempt_code'], $product->lineFields()['tax_rate']]);
    }

    public function test_a_product_reason_matches_its_category(): void
    {
        $this->portal('/portal/products', [
            '_token' => self::TOKEN, 'name' => 'Rent', 'name_ar' => 'إيجار', 'unit_code' => 'C62', 'unit_price' => '100',
            'tax_rate' => '0', 'tax_category' => 'E', 'exempt_code' => 'VATEX-SA-35',
        ])->assertSessionHasErrors('exempt_code');
    }

    /** Asked once, on the product, not on every invoice that uses it. */
    public function test_zero_or_exempt_needs_a_reason(): void
    {
        foreach (['Z', 'E'] as $category) {
            $this->portal('/portal/products', [
                '_token' => self::TOKEN, 'name' => 'Advice '.$category, 'name_ar' => 'استشارة', 'unit_code' => 'C62', 'unit_price' => '100',
                'tax_rate' => '0', 'tax_category' => $category, 'exempt_code' => '',
            ])->assertSessionHasErrors(['exempt_code' => __('Choose the reason this product is zero-rated, exempt or out of scope. Every invoice line in that category needs one.')]);
        }

        $this->assertSame(0, Product::acrossTenants()->count());
    }

    /** A line naming the product and no treatment of its own takes the product's. */
    public function test_a_line_takes_its_product_treatment(): void
    {
        $product = $this->product(['tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-35', 'tax_rate' => 0]);

        $line = $this->api(['lines' => [['description' => 'Insulin pen', 'description_ar' => 'قلم إنسولين', 'quantity' => 1, 'unit_price' => '189.90', 'product_id' => $product->id]]])
            ->assertCreated()->json('data.invoice.lines.0');

        $this->assertSame(['Z', 'VATEX-SA-35', 'Medicines and medical equipment'], [$line['tax_category'], $line['exempt_code'], $line['exempt_reason']]);
    }

    /** And a line saying otherwise keeps what it says. */
    public function test_a_line_overrides_its_product(): void
    {
        $product = $this->product(['tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-35', 'tax_rate' => 0]);

        $line = $this->api(['lines' => [['description' => 'Insulin case', 'description_ar' => 'حافظة', 'quantity' => 1, 'unit_price' => '20', 'product_id' => $product->id, 'tax_category' => 'S', 'tax_rate' => 15]]])
            ->assertCreated()->json('data.invoice.lines.0');

        $this->assertSame(['S', null], [$line['tax_category'], $line['exempt_code']]);
    }

    public function test_a_citizen_needs_a_national_id(): void
    {
        $this->portal('/portal/customers', $this->customerForm(['buyer_id_scheme' => 'IQA', 'buyer_id' => '2123456789']))
            ->assertSessionHasErrors('buyer_id_scheme');

        $this->portal('/portal/customers', $this->customerForm(['buyer_id' => '99']))
            ->assertSessionHasErrors('buyer_id');

        $this->portal('/portal/customers', $this->customerForm())->assertSessionHasNoErrors();
        $this->assertSame('VATEX-SA-HEA', Customer::acrossTenants()->sole()->exempt_code);
    }

    /**
     * A citizen's consultation: the customer's zero rating and national ID
     * fill the invoice (BR-KSA-49), while a product with a treatment of its
     * own keeps it.
     */
    public function test_a_citizen_customer_fills_the_invoice(): void
    {
        $this->portal('/portal/customers', $this->customerForm())->assertSessionHasNoErrors();
        $customer = Customer::acrossTenants()->sole();
        $cosmetic = $this->product(['name' => 'Sunscreen', 'tax_category' => 'S', 'tax_rate' => 15]);

        $invoice = $this->api(['customer_id' => $customer->id, 'lines' => [
            ['description' => 'Consultation', 'description_ar' => 'استشارة', 'quantity' => 1, 'unit_price' => '300'],
            ['description' => 'Sunscreen', 'description_ar' => 'واقي شمس', 'quantity' => 1, 'unit_price' => '50', 'product_id' => $cosmetic->id],
        ]])->assertCreated()->json('data.invoice');

        $this->assertSame(['1123456789', 'NAT'], [$invoice['buyer_id'], $invoice['buyer_id_scheme']]);
        $this->assertSame(['Z', 'S'], array_column($invoice['lines'], 'tax_category'));
        $this->assertSame('VATEX-SA-HEA', $invoice['lines'][0]['exempt_code']);
        $this->assertSame('VATEX-SA-HEA', $customer->buyerFields()['default_exempt_code']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customerForm(array $overrides = []): array
    {
        return [
            '_token' => self::TOKEN, 'name' => 'Fahad Al Harbi', 'name_ar' => 'فهد الحربي', 'country_code' => 'SA',
            'buyer_id_scheme' => 'NAT', 'buyer_id' => '1123456789', 'exempt_code' => 'VATEX-SA-HEA',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes): Product
    {
        $product = new Product(['org_id' => $this->organization->id, 'name' => 'Insulin pen', 'name_ar' => 'قلم إنسولين', 'unit_code' => 'C62', 'unit_price' => '100', 'tax_rate' => 15, ...$attributes]);
        $product->save();

        return $product;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function api(array $payload): TestResponse
    {
        $token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($this->admin);

        return $this->withToken($token)->postJson('/api/invoices', [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Fahad Al Harbi',
            'buyer_name_ar' => 'فهد الحربي',
            ...$payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function portal(string $uri, array $data): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($this->admin)
            ->withSession(['_token' => self::TOKEN])
            ->post($uri, $data));
    }
}
