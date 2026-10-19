<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * An invoice that does not say whether its prices include VAT follows the
 * organization's setting, wherever it comes from: the same effective value
 * the portal form starts on - the organization's own choice, else what its
 * business type suggests, else the platform's (invoices.prices_include_vat).
 */
class PricesIncludeVatDefaultTest extends TestCase
{
    use RefreshDatabase;

    private Organization $shop;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // A retailer's type suggests prices with VAT in them (Catalogue).
        $this->shop = Organization::create(['name' => 'Corner Shop', 'country' => 'SA', 'business_type' => 'retail']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->shop->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_the_api_follows_the_business_type(): void
    {
        $this->assertTrue($this->effective());

        $this->api()->assertCreated();

        $this->assertInclusive(true);
    }

    public function test_the_pipeline_follows_the_business_type(): void
    {
        License::create([
            'org_id' => $this->shop->id, 'api_key' => 'cp_test_shop', 'api_secret_hash' => License::hashSecret('secret'),
            'organization_name' => 'Corner Shop', 'contact_email' => 'ops@example.test', 'environment' => 'sandbox',
            'tier' => 'professional', 'status' => 'active', 'scopes' => ['invoice.submit'],
        ]);

        $this->withHeaders(['X-API-Key' => 'cp_test_shop', 'X-API-Secret' => 'secret'])->postJson('/api/v1/pipeline/submit', [
            ...$this->payload(),
            'org_id' => $this->shop->id,
            'document_type' => 'invoice',
            'auto_submit' => false,
        ])->assertSuccessful();

        $this->assertInclusive(true);
    }

    public function test_the_organization_own_choice_wins(): void
    {
        $this->shop->forceFill(['prices_include_vat' => false])->save();
        $this->assertFalse($this->effective());

        $this->api()->assertCreated();

        $this->assertInclusive(false);
    }

    public function test_the_invoice_own_flag_wins(): void
    {
        $this->api(['prices_include_vat' => false])->assertCreated();

        $this->assertInclusive(false);
    }

    /** What the portal form starts on for the organization. */
    private function effective(): bool
    {
        return (bool) app(Customizations::class)->get('invoices.prices_include_vat', Scope::organization($this->shop->id));
    }

    private function assertInclusive(bool $inclusive): void
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->sole());

        $this->assertSame($inclusive, (bool) $invoice->prices_include_vat);
        // 115.00 with VAT in it is 100.00 and 15.00; without, 115.00 and 17.25.
        $this->assertSame($inclusive ? '115.00' : '132.25', (string) $invoice->total);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'invoice_number' => 'SALE-1',
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'lines' => [['description' => 'Basket', 'description_ar' => 'سلة', 'quantity' => 1, 'unit_price' => '115.00']],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function api(array $overrides = []): TestResponse
    {
        $token = JWTAuth::claims(['org_id' => $this->shop->id, 'role' => 'admin'])->fromUser($this->admin);

        return $this->withToken($token)->postJson('/api/invoices', $this->payload($overrides));
    }
}
