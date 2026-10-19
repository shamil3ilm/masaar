<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A sale entered once and billed in two shares through the API: a
 * simplified invoice to the patient and a tax invoice to the insurer,
 * linked both ways and adding up to the sale.
 */
class InsuranceSplitApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $pharmacy;

    private User $admin;

    private Customer $insurer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pharmacy = Organization::create(['name' => 'Al Dawaa Pharmacy', 'country' => 'SA', 'vat_number' => '399999999900003']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->pharmacy->id, ['role' => 'admin', 'status' => 'active']);

        $this->insurer = new Customer([
            'org_id' => $this->pharmacy->id, 'name' => 'Gulf Health Insurance', 'name_ar' => 'الخليج للتأمين الصحي',
            'vat_number' => '311111111100003', 'is_insurer' => true, 'street' => 'King Abdullah Road', 'building_number' => '7788',
            'district' => 'Al Malqa', 'city' => 'Riyadh', 'postal_code' => '13521', 'country_code' => 'SA',
        ]);
        Customer::withoutTenantScope(fn () => $this->insurer->save());
    }

    public function test_a_sale_becomes_two_linked_invoices(): void
    {
        $data = $this->split()->assertCreated()->json('data');

        $patient = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($data['invoices']['patient']['id']));
        $insurer = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($data['invoices']['insurer']['id']));

        $this->assertSame(['simplified', 'standard'], [$patient->type->value, $insurer->type->value]);
        $this->assertSame($patient->insurance_split_id, $insurer->insurance_split_id);
        $this->assertSame($insurer->id, $patient->insuranceSplit->share('insurer')->id);
        $this->assertSame($patient->id, $insurer->insuranceSplit->share('patient')->id);

        // The insurer is the insurer's invoice's buyer, with its references.
        $this->assertSame(['311111111100003', 'CLM-2026-0042', 'POL-77', 'MEM-1234'], [$insurer->buyer_vat_number, $insurer->order_reference, $insurer->contract_reference, $insurer->buyer_reference]);
        $this->assertNull($insurer->buyer_id);

        // Prices include VAT, so the two totals come exactly to the sale's.
        $this->assertSame('460.00', $data['split']['sale_total']);
        $this->assertSame('460.00', bcadd((string) $patient->total, (string) $insurer->total, 2));
        $this->assertSame('92.00', (string) $patient->total);
    }

    /** A medicine stays zero-rated in both shares; each share's VAT is its own. */
    public function test_each_share_keeps_its_vat_treatment(): void
    {
        $data = $this->split()->assertCreated()->json('data');

        foreach (['patient', 'insurer'] as $share) {
            $lines = collect($data['invoices'][$share]['lines']);
            $this->assertSame(['Z', 'S'], $lines->pluck('tax_category')->all(), "the {$share}'s categories");
            $this->assertSame('VATEX-SA-35', $lines->first()['exempt_code']);
        }
    }

    /**
     * A citizen's private healthcare is zero-rated on the patient's share,
     * with their national ID, and standard-rated on the insurer's, whose
     * buyer is the insurer (BR-KSA-49).
     */
    public function test_insurers_pay_vat_on_citizen_care(): void
    {
        $data = $this->split([
            'buyer_name' => 'Fahad Al Harbi', 'buyer_name_ar' => 'فهد الحربي', 'buyer_id' => '1012345678', 'buyer_id_scheme' => 'NAT',
            'lines' => [['description' => 'Consultation', 'description_ar' => 'استشارة', 'quantity' => 1, 'unit_price' => '300.00', 'exempt_code' => 'VATEX-SA-HEA']],
        ])->assertCreated()->json('data');

        $patient = $data['invoices']['patient']['lines'][0];
        $insurer = $data['invoices']['insurer']['lines'][0];

        $this->assertSame(['Z', 'VATEX-SA-HEA', '60.00', '0.00'], [$patient['tax_category'], $patient['exempt_code'], $patient['line_total'], $patient['tax_amount']]);
        $this->assertSame(['S', null], [$insurer['tax_category'], $insurer['exempt_code']]);
        $this->assertSame('NAT', $data['invoices']['patient']['buyer_id_scheme']);
        $this->assertNull($data['invoices']['insurer']['buyer_id_scheme']);
    }

    public function test_lines_can_go_to_one_payer(): void
    {
        $data = $this->split([
            'lines' => [
                ['description' => 'Sunscreen', 'description_ar' => 'واقي شمس', 'quantity' => 1, 'unit_price' => '40.00', 'payer' => 'patient'],
                ['description' => 'Insulin pen', 'description_ar' => 'قلم إنسولين', 'quantity' => 2, 'unit_price' => '100.00', 'exempt_code' => 'VATEX-SA-35', 'payer' => 'insurer'],
            ],
            'split' => ['method' => 'lines', 'insurer_customer_id' => $this->insurer->id],
        ])->assertCreated()->json('data');

        $this->assertSame(['Sunscreen'], collect($data['invoices']['patient']['lines'])->pluck('description')->all());
        $this->assertSame(['Insulin pen'], collect($data['invoices']['insurer']['lines'])->pluck('description')->all());
    }

    /** An insurer given in full is checked as a tax invoice's buyer is. */
    public function test_an_insurer_needs_its_vat_number(): void
    {
        $this->split(['split' => ['method' => 'percent', 'percent' => 20, 'insurer' => ['name' => 'Unknown Insurer', 'name_ar' => 'شركة تأمين']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['insurer.buyer_vat_number', 'insurer.buyer_address'], 'error.details');
    }

    public function test_a_retry_returns_the_same_split(): void
    {
        $first = $this->split([], 'split-key-1')->assertCreated()->json('data.split.id');

        $this->split([], 'split-key-1')->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.split.id', $first);
        $this->assertSame(1, InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->count()));
        $this->assertSame(2, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    public function test_the_uae_bills_no_split(): void
    {
        $this->pharmacy->forceFill(['country' => 'AE'])->save();

        $this->split()->assertUnprocessable()->assertJsonValidationErrors(['split'], 'error.details');
        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function split(array $overrides = [], ?string $key = null): TestResponse
    {
        $token = JWTAuth::claims(['org_id' => $this->pharmacy->id, 'role' => 'admin'])->fromUser($this->admin);

        return $this->withToken($token)->withHeaders($key === null ? [] : ['Idempotency-Key' => $key])->postJson('/api/invoices/splits', [
            'issue_date' => now()->toDateString(),
            'prices_include_vat' => true,
            'lines' => [
                ['description' => 'Insulin pen', 'description_ar' => 'قلم إنسولين', 'quantity' => 2, 'unit_price' => '115.00', 'exempt_code' => 'VATEX-SA-35'],
                ['description' => 'Glucose meter', 'description_ar' => 'جهاز قياس السكر', 'quantity' => 1, 'unit_price' => '230.00'],
            ],
            'split' => [
                'method' => 'percent', 'percent' => 20, 'insurer_customer_id' => $this->insurer->id,
                'claim_reference' => 'CLM-2026-0042', 'policy_number' => 'POL-77', 'member_id' => 'MEM-1234',
            ],
            ...$overrides,
        ]);
    }
}
