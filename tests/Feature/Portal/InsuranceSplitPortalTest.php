<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Split with insurer, in the portal: the invoice form's option, the two
 * linked drafts it saves, issuing both, the insurer's decision and the
 * report of what insurers owe.
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
            'name' => 'Al Dawaa Pharmacy', 'name_ar' => 'صيدلية الدواء', 'country' => 'SA', 'vat_number' => '300000000000003', 'business_type' => 'pharmacy',
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
            ->assertSee('name="split[method]"', false)
            ->assertSee('formaction="'.route('portal.invoices.splits.store').'"', false)
            ->assertSee('Gulf Health Insurance'));
    }

    /** Any business can split, the option folded away until wanted. */
    public function test_any_business_can_split(): void
    {
        $this->pharmacy->forceFill(['business_type' => 'wholesale'])->save();

        $html = $this->asRequest(fn () => $this->portal()->get('/portal/invoices/create')->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/<details\s+(?![^>]*\bopen\b)[^>]*data-split-sale/', (string) $html);
    }

    public function test_the_form_saves_two_linked_drafts(): void
    {
        $this->save(['split' => ['method' => 'lines', 'insurer_customer_id' => $this->insurer->id, 'claim_reference' => 'CLM-9'], 'insurer_lines' => '2'])
            ->assertSessionHasNoErrors();

        $split = InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->sole());

        $this->assertSame(['Sunscreen'], $split->share('patient')->lines->pluck('description')->all());
        $this->assertSame(['Insulin pen'], $split->share('insurer')->lines->pluck('description')->all());
        $this->assertSame('CLM-9', $split->share('insurer')->order_reference);
    }

    /** Both shown with their link, issued together, the decision recorded. */
    public function test_both_are_issued_and_settled(): void
    {
        $this->save()->assertSessionHasNoErrors();
        $split = InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->sole());
        $patient = $split->share('patient');
        $insurer = $split->share('insurer');

        $this->asRequest(fn () => $this->portal()->get(route('portal.invoices.show', $patient->id))
            ->assertOk()
            ->assertSee('data-split', false)
            ->assertSee(route('portal.invoices.show', $insurer->id), false)
            ->assertSee(route('portal.invoices.splits.issue', $split->id), false));

        $this->asRequest(fn () => $this->portal()->post(route('portal.invoices.splits.issue', $split->id), ['_token' => self::TOKEN])
            ->assertRedirect(route('portal.invoices.show', $patient->id)));

        $this->assertNotSame(InvoiceStatus::Draft, $patient->fresh()->status);
        $this->assertNotSame(InvoiceStatus::Draft, $insurer->fresh()->status);

        $this->asRequest(fn () => $this->portal()->post(route('portal.invoices.splits.settle', $split->id), ['_token' => self::TOKEN, 'approved' => '150.00'])
            ->assertSessionHasNoErrors());

        $split->refresh();
        $this->assertSame(InsuranceSplit::SETTLED, $split->status);
        $this->assertSame((string) bcsub((string) $insurer->fresh()->total, '150.00', 2), (string) $split->rebill->total);
    }

    public function test_the_report_shows_what_insurers_owe(): void
    {
        $this->save()->assertSessionHasNoErrors();
        $split = InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->sole());
        $this->asRequest(fn () => $this->portal()->post(route('portal.invoices.splits.issue', $split->id), ['_token' => self::TOKEN]));
        $owed = number_format((float) Invoice::withoutTenantScope(fn () => Invoice::query()->where('split_role', 'insurer')->value('total')), 2);

        $this->asRequest(fn () => $this->portal()->get(route('portal.reports.insurers'))
            ->assertOk()
            ->assertSee('Gulf Health Insurance')
            ->assertSee($owed));
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
