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
 * A sale an insurer pays for, through the API, billed as ZATCA's General VAT
 * Guideline Healthcare (January 2023) has it: one invoice in the name of
 * the recipient (§5.2 p24) - the patient, presumed so "even where ... it is
 * the third party who is responsible for paying" (§5.1 p21), with what is
 * for the account of the insurer and of the patient (Example 10 p23); or
 * the insurer, when it contracted for the care and the patient pays nothing
 * (Example 11 p23).
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

    /** One simplified invoice to the patient, with the two accounts beside it (§5.2 p24, Example 10). */
    public function test_the_patient_is_the_recipient(): void
    {
        $data = $this->split()->assertCreated()->json('data');
        $split = $this->record($data['split']['id']);
        $invoice = $split->sale();

        $this->assertSame(['patient'], array_keys($data['invoices']));
        $this->assertSame(['simplified', 'patient', 'Sara Al Qahtani'], [$invoice->type->value, $invoice->split_role, $invoice->buyer_name]);
        $this->assertCount(2, $invoice->lines);
        $this->assertNull($invoice->buyer_vat_number);

        // 20% of 460.00 for the patient, the rest for the insurer, exactly.
        $this->assertSame(['460.00', '92.00', '368.00'], [(string) $invoice->total, (string) $split->patient_share, (string) $split->insurer_share]);
        $this->assertSame(['patient', 'Gulf Health Insurance', 'CLM-2026-0042'], [$split->recipient, $split->insurer_name, $split->claim_reference]);
    }

    /**
     * A citizen's private healthcare is zero-rated on their own invoice,
     * though the insurer pays most of it: the State bears the VAT for Saudi
     * nationals benefiting from private healthcare (§5.3.3, p26).
     */
    public function test_citizens_are_zero_rated_whoever_pays(): void
    {
        $data = $this->split([
            'buyer_name' => 'Fahad Al Harbi', 'buyer_name_ar' => 'فهد الحربي', 'buyer_id' => '1047392816', 'buyer_id_scheme' => 'NAT',
            'prices_include_vat' => false,
            'lines' => [['description' => 'Consultation', 'description_ar' => 'استشارة', 'quantity' => 1, 'unit_price' => '300.00', 'exempt_code' => 'VATEX-SA-HEA']],
        ])->assertCreated()->json('data');

        $line = $data['invoices']['patient']['lines'][0];
        $this->assertSame(['Z', 'VATEX-SA-HEA', '0.00'], [$line['tax_category'], $line['exempt_code'], $line['tax_amount']]);
        $this->assertSame(['60.00', '240.00'], [$data['split']['patient_share'], $data['split']['insurer_share']]);
    }

    /**
     * The insurer contracted for the care: a tax invoice to it, which it
     * deducts (Example 11, p24-25). A citizen's healthcare on it is standard-
     * rated: its recipient is the insurer, not a Saudi national (§5.3.3).
     */
    public function test_an_insurer_recipient_is_invoiced(): void
    {
        $data = $this->split([
            'buyer_name' => 'Fahad Al Harbi', 'buyer_name_ar' => 'فهد الحربي', 'buyer_id' => '1047392816', 'buyer_id_scheme' => 'NAT',
            'prices_include_vat' => false,
            'lines' => [
                ['description' => 'Consultation', 'description_ar' => 'استشارة', 'quantity' => 1, 'unit_price' => '300.00', 'exempt_code' => 'VATEX-SA-HEA'],
                ['description' => 'Dressing', 'description_ar' => 'ضماد', 'quantity' => 2, 'unit_price' => '100.00'],
            ],
            'split' => ['recipient' => 'insurer', 'insurer_customer_id' => $this->insurer->id, 'claim_reference' => 'CLM-7', 'policy_number' => 'POL-77', 'member_id' => 'MEM-1'],
        ])->assertCreated()->json('data');

        $split = $this->record($data['split']['id']);
        $invoice = $split->sale();

        $this->assertSame(['standard', 'insurer', '311111111100003'], [$invoice->type->value, $invoice->split_role, $invoice->buyer_vat_number]);
        $this->assertNull($invoice->buyer_id);
        $this->assertSame(['S', 'S'], $invoice->lines->pluck('tax_category')->all());
        $this->assertSame(['CLM-7', 'POL-77', 'MEM-1'], [$invoice->order_reference, $invoice->contract_reference, $invoice->buyer_reference]);
        // The patient as the sale named them, and each line's own treatment,
        // for care the insurer may not cover (§5.3.2).
        $this->assertSame(['Fahad Al Harbi', '1047392816', 'VATEX-SA-HEA'], [$split->patient_name, $split->patient_id, $split->line_treatments[0]['exempt_code']]);
        $this->assertSame(['0.00', '575.00'], [(string) $split->patient_share, (string) $split->insurer_share]);
    }

    /** A patient who pays a share is obliged to pay, so is the recipient (§5.1, Example 11). */
    public function test_a_paying_patient_is_the_recipient(): void
    {
        $this->split(['split' => ['recipient' => 'insurer', 'method' => 'percent', 'percent' => 20, 'insurer_customer_id' => $this->insurer->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['split.recipient'], 'error.details');
        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    public function test_lines_set_the_patient_share(): void
    {
        $data = $this->split([
            'lines' => [
                ['description' => 'Sunscreen', 'description_ar' => 'واقي شمس', 'quantity' => 1, 'unit_price' => '46.00', 'payer' => 'patient'],
                ['description' => 'Insulin pen', 'description_ar' => 'قلم إنسولين', 'quantity' => 2, 'unit_price' => '100.00', 'exempt_code' => 'VATEX-SA-35', 'payer' => 'insurer'],
            ],
            'split' => ['method' => 'lines', 'insurer_customer_id' => $this->insurer->id],
        ])->assertCreated()->json('data');

        $this->assertSame(['46.00', '200.00'], [$data['split']['patient_share'], $data['split']['insurer_share']]);
    }

    /** An insurer is a buyer only when it is the recipient, and then needs its VAT number. */
    public function test_an_insurer_buyer_needs_its_vat(): void
    {
        $insurer = ['name' => 'Unknown Insurer', 'name_ar' => 'شركة تأمين'];

        $this->split(['split' => ['method' => 'percent', 'percent' => 20, 'insurer' => $insurer]])->assertCreated();
        $this->split(['split' => ['recipient' => 'insurer', 'insurer' => $insurer]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['insurer.buyer_vat_number', 'insurer.buyer_address'], 'error.details');
    }

    public function test_a_retry_returns_the_same_split(): void
    {
        $first = $this->split([], 'split-key-1')->assertCreated()->json('data.split.id');

        $this->split([], 'split-key-1')->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.split.id', $first);
        $this->assertSame(1, InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->count()));
        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    public function test_a_reused_key_is_refused(): void
    {
        $this->split([], 'split-key-2')->assertCreated();

        $this->split(['buyer_name' => 'Another Patient'], 'split-key-2')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame(1, InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->count()));
    }

    public function test_the_uae_bills_no_split(): void
    {
        $this->pharmacy->forceFill(['country' => 'AE'])->save();

        $this->split()->assertUnprocessable()->assertJsonValidationErrors(['split'], 'error.details');
        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    private function record(string $id): InsuranceSplit
    {
        return InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->with('invoices.lines')->findOrFail($id));
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
            'buyer_name' => 'Sara Al Qahtani',
            'buyer_name_ar' => 'سارة القحطاني',
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
