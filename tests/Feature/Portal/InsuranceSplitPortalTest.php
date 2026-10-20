<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Split with insurer, in the portal: the invoice form's option, the one
 * invoice it saves to the recipient with the insurer's share beside it
 * (ZATCA General VAT Guideline Healthcare, January 2023, §5.1-5.2), the
 * insurer's decision (§5.3) and the report of what insurers owe.
 */
class InsuranceSplitPortalTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'split-portal-token';

    private Organization $pharmacy;

    private User $member;

    private Customer $insurer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pharmacy = Organization::create([
            'name' => 'Al Dawaa Pharmacy', 'name_ar' => 'صيدلية الدواء', 'country' => 'SA', 'vat_number' => '300000000000003', 'cr_number' => '1010101010', 'business_type' => 'pharmacy',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->pharmacy->id, ['role' => 'member', 'status' => 'active']);

        $this->insurer = new Customer([
            'org_id' => $this->pharmacy->id, 'name' => 'Gulf Health Insurance', 'name_ar' => 'الخليج للتأمين الصحي',
            'vat_number' => '311111111100003', 'is_insurer' => true, 'street' => 'King Abdullah Road', 'building_number' => '7788',
            'district' => 'Al Malqa', 'city' => 'Riyadh', 'postal_code' => '13521', 'country_code' => 'SA',
        ]);
        Customer::withoutTenantScope(fn () => $this->insurer->save());
    }

    /** A pharmacy's form opens on it; the insurer is one of its customers. */
    public function test_the_form_offers_the_split(): void
    {
        $this->asRequest(fn () => $this->portal()->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee('data-split-sale', false)
            ->assertSee('name="split[recipient]"', false)
            ->assertSee('name="split[method]"', false)
            ->assertSee('formaction="'.route('portal.invoices.splits.store').'"', false)
            ->assertSee('Gulf Health Insurance'));
    }

    /** Any business can use it, folded away until wanted. */
    public function test_any_business_can_split(): void
    {
        $this->pharmacy->forceFill(['business_type' => 'wholesale'])->save();

        $html = $this->asRequest(fn () => $this->portal()->get('/portal/invoices/create')->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/<details\s+(?![^>]*\bopen\b)[^>]*data-split-sale/', (string) $html);
    }

    /** One invoice to the patient, the insurer's lines its share. */
    public function test_the_form_saves_one_invoice(): void
    {
        $this->save(['split' => ['method' => 'lines', 'insurer_customer_id' => $this->insurer->id, 'claim_reference' => 'CLM-9'], 'insurer_lines' => '2'])
            ->assertSessionHasNoErrors();

        $split = $this->split();

        $this->assertSame(['Sunscreen', 'Insulin pen'], $split->sale()->lines->pluck('description')->all());
        $this->assertSame(['patient', '40.00', '200.00', 'CLM-9'], [$split->recipient, (string) $split->patient_share, (string) $split->insurer_share, $split->claim_reference]);
    }

    /** Issued as any draft; then what the insurer pays, the patient owing the rest (§5.3.2). */
    public function test_the_decision_is_recorded(): void
    {
        $this->save()->assertSessionHasNoErrors();
        $invoice = $this->split()->sale();

        $this->asRequest(fn () => $this->portal()->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('data-split', false)
            ->assertSee('Gulf Health Insurance')
            ->assertDontSee(route('portal.invoices.splits.settle', $this->split()->id), false));

        $this->asRequest(fn () => $this->portal()->post(route('portal.invoices.issue', $invoice->id), ['_token' => self::TOKEN]));
        $this->assertNotSame(InvoiceStatus::Draft, $invoice->fresh()->status);

        $this->asRequest(fn () => $this->portal()->get(route('portal.invoices.show', $invoice->id))
            ->assertSee(route('portal.invoices.splits.settle', $this->split()->id), false));

        $this->asRequest(fn () => $this->portal()->post(route('portal.invoices.splits.settle', $this->split()->id), ['_token' => self::TOKEN, 'approved' => '150.00'])
            ->assertSessionHasNoErrors());

        $split = $this->split();
        $this->assertSame([InsuranceSplit::SETTLED, InsuranceSplit::NOT_COVERED, '150.00'], [$split->status, $split->decision, $split->insurerOwes()]);
    }

    /** The insurer, the recipient, at its agreed price: credited on its invoice (§5.3.1, Example 12). */
    public function test_an_insurer_recipient_is_credited(): void
    {
        $this->save([
            'lines' => [['description' => 'Dressing', 'description_ar' => 'ضماد', 'quantity' => '2', 'unit_price' => '100.00', 'tax_category' => 'S', 'tax_rate' => '15']],
            'prices_include_vat' => '0',
            'split' => ['recipient' => 'insurer', 'method' => 'percent', 'insurer_customer_id' => $this->insurer->id],
        ])->assertSessionHasNoErrors();
        $invoice = $this->split()->sale();
        $this->assertSame(['standard', '311111111100003'], [$invoice->type->value, $invoice->buyer_vat_number]);

        $this->asRequest(fn () => $this->portal()->post(route('portal.invoices.issue', $invoice->id), ['_token' => self::TOKEN]));
        $this->asRequest(fn () => $this->portal()->post(route('portal.invoices.splits.settle', $this->split()->id), ['_token' => self::TOKEN, 'approved' => '150.00', 'decision' => 'price'])
            ->assertSessionHasNoErrors());

        $this->assertSame('50.00', (string) $this->split()->creditNote->subtotal);
    }

    public function test_the_report_shows_what_insurers_owe(): void
    {
        $this->save()->assertSessionHasNoErrors();
        $this->asRequest(fn () => $this->portal()->post(route('portal.invoices.issue', $this->split()->sale()->id), ['_token' => self::TOKEN]));

        $this->asRequest(fn () => $this->portal()->get(route('portal.reports.insurers'))
            ->assertOk()
            ->assertSee('Gulf Health Insurance')
            ->assertSee(number_format((float) $this->split()->insurer_share, 2)));
    }

    private function split(): InsuranceSplit
    {
        return InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->with('invoices.lines')->sole());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function save(array $overrides = []): TestResponse
    {
        return $this->asRequest(fn () => $this->portal()->post(route('portal.invoices.splits.store'), [
            '_token' => self::TOKEN,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'prices_include_vat' => '1',
            'buyer_name' => 'Sara Al Qahtani',
            'buyer_name_ar' => 'سارة القحطاني',
            'lines' => [
                ['description' => 'Sunscreen', 'description_ar' => 'واقي شمس', 'quantity' => '1', 'unit_price' => '40.00', 'tax_category' => 'S', 'tax_rate' => '15'],
                ['description' => 'Insulin pen', 'description_ar' => 'قلم إنسولين', 'quantity' => '2', 'unit_price' => '100.00', 'tax_category' => 'Z', 'tax_rate' => '0', 'exempt_code' => 'VATEX-SA-35'],
            ],
            'split' => ['method' => 'percent', 'percent' => '20', 'insurer_customer_id' => $this->insurer->id],
            ...$overrides,
        ]));
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->pharmacy->id, '_token' => self::TOKEN]);
    }
}
