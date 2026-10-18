<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Product;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A UAE invoice may be in English alone, whatever a Saudi one requires.
 *
 * The UAE Electronic Invoicing Guidelines (v1.1) §5.1, p11: the framework
 * "is designed to support both the Arabic and English languages"; the
 * sample invoice on p34 is in English only. So nothing a UAE organization
 * sends is refused or warned about for want of Arabic, and its customers
 * and products keep their Arabic names optional.
 */
class UaeArabicOptionalTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private const TOKEN = 'uae-arabic-token';

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $this->gulf = $this->uaeOrganization();
    }

    public function test_uae_invoice_needs_no_arabic(): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->gulf->id, ['role' => 'admin', 'status' => 'active']);

        $this->withToken(JWTAuth::claims(['org_id' => $this->gulf->id, 'role' => 'admin'])->fromUser($user))
            ->postJson('/api/compliance/ae/invoices', [
                'issue_date' => '2026-10-10', 'due_date' => '2026-11-09', 'payment_means_code' => '30',
                'buyer_name' => 'Noor Electronics LLC', 'buyer_vat_number' => '100765432100003',
                'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
                'buyer_endpoint' => '0235:1007654321', 'buyer_subdivision' => 'AUH',
                'notes' => 'Delivered to the Abu Dhabi warehouse',
                'lines' => [[
                    'item_name' => 'Consulting', 'description' => 'Consulting services, October', 'quantity' => 2,
                    'unit_code' => 'DAY', 'unit_price' => 500, 'tax_category' => 'S', 'tax_rate' => 5,
                ]],
            ])
            ->assertCreated()
            ->assertJsonMissingPath('data.warnings');
    }

    public function test_uae_rules_require_no_arabic(): void
    {
        $language = app(JurisdictionResolver::class)->forRules($this->gulf)->languageRules();

        $this->assertFalse($language->requiresArabic());
        $this->assertSame(['nullable', 'string', 'max:255'], $language->arabicNameRules($language::PRODUCT));
    }

    public function test_uae_product_needs_no_arabic_name(): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->gulf->id, ['role' => 'member', 'status' => 'active']);

        $this->asRequest(fn (): TestResponse => $this->actingAs($member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/products', ['_token' => self::TOKEN, 'name' => 'Widget', 'unit_code' => 'C62', 'unit_price' => '10', 'tax_rate' => '5']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Product::withoutTenantScope(fn () => Product::query()->where('org_id', $this->gulf->id)->count()));
    }
}
