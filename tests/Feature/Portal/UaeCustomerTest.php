<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;

/**
 * A UAE organization's customers keep the buyer's Peppol endpoint and
 * emirate, from the customer form and from "Save this buyer" on the UAE
 * invoice form, so the invoice form's picker fills them in. A Saudi
 * organization's customers are in JurisdictionIsolationTest.
 */
class UaeCustomerTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private const TOKEN = 'uae-customer-token';

    private Organization $gulf;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $this->gulf = $this->uaeOrganization();
        $this->member = $this->member($this->gulf);
    }

    public function test_customer_form_saves_uae_fields(): void
    {
        $this->portal()->post('/portal/customers', $this->customer())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('portal.customers.index'));

        $customer = $this->customers()->sole();
        $this->assertSame(['0235:1007654321', 'AUH'], [$customer->peppol_endpoint, $customer->subdivision]);

        $this->portal()->post(route('portal.customers.update', $customer->id), $this->customer(['peppol_endpoint' => '0235:9900000098', 'subdivision' => 'DXB']))
            ->assertSessionHasNoErrors();
        $this->assertSame(['0235:9900000098', 'DXB'], [$customer->fresh()->peppol_endpoint, $customer->fresh()->subdivision]);
    }

    public function test_bad_endpoint_and_emirate_refused(): void
    {
        foreach (['1007654321', '0235:12345', '9930:DE123456789', '0235:10076543210'] as $endpoint) {
            $this->portal()->post('/portal/customers', $this->customer(['peppol_endpoint' => $endpoint]))
                ->assertSessionHasErrors('peppol_endpoint');
        }

        $this->portal()->post('/portal/customers', $this->customer(['subdivision' => 'Dubai']))
            ->assertSessionHasErrors('subdivision');

        $this->assertSame(0, $this->customers()->count());
    }

    public function test_buyer_abroad_keeps_its_region(): void
    {
        $this->portal()->post('/portal/customers', $this->customer([
            'country_code' => 'GB', 'subdivision' => 'Greater London', 'peppol_endpoint' => '0235:9900000099', 'vat_number' => '',
        ]))->assertSessionHasNoErrors();

        $customer = $this->customers()->sole();
        $this->assertSame('Greater London', $customer->subdivision);
        $this->assertSame('export', $customer->uaeBuyerFields()['buyer_endpoint_choice']);
        $this->assertSame('', $customer->uaeBuyerFields()['buyer_endpoint']);
    }

    public function test_forms_show_the_uae_fields(): void
    {
        $this->asRequest(fn () => $this->portal()->get('/portal/customers/new')
            ->assertOk()
            ->assertSee('name="peppol_endpoint"', false)
            ->assertSee('name="subdivision"', false)
            ->assertSee('value="AE"', false));

        $this->asRequest(fn () => $this->portal()->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee('name="save_customer"', false));
    }

    public function test_uae_invoice_form_saves_buyer(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->member)
            ->withSession(['portal_org_id' => $this->gulf->id, '_token' => self::TOKEN])
            ->from('/portal/invoices/create')
            ->post('/portal/invoices/uae', [...$this->invoiceForm(), 'save_customer' => '1', '_token' => self::TOKEN])
            ->assertSessionHasNoErrors()
            ->assertRedirect());

        $customer = $this->customers()->sole();
        $this->assertSame(['Noor Electronics LLC', '100765432100003', 'Abu Dhabi'], [$customer->name, $customer->vat_number, $customer->city]);
        $this->assertSame(['0235:1007654321', 'AUH'], [$customer->peppol_endpoint, $customer->subdivision]);
        $this->assertSame('0235:1007654321', $customer->uaeBuyerFields()['buyer_endpoint']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customer(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Noor Electronics LLC',
            'vat_number' => '100765432100003',
            'street' => 'Khalifa Street',
            'city' => 'Abu Dhabi',
            'country_code' => 'AE',
            'peppol_endpoint' => '0235:1007654321',
            'subdivision' => 'AUH',
        ], $overrides);
    }

    /**
     * The UAE invoice form as a browser sends it.
     *
     * @return array<string, mixed>
     */
    private function invoiceForm(): array
    {
        return [
            'invoice_number' => '',
            'document_type' => 'invoice',
            'document_category' => 'tax',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'AED',
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_endpoint_choice' => 'own',
            'buyer_endpoint' => '0235:1007654321',
            'buyer_address' => ['street' => 'Khalifa Street', 'building_number' => '', 'city' => 'Abu Dhabi', 'postal_code' => '', 'country_code' => 'AE'],
            'buyer_subdivision' => 'AUH',
            'buyer_legal_registration' => ['type' => '', 'id' => '', 'authority' => ''],
            'delivery_address' => ['street' => '', 'city' => '', 'subdivision' => '', 'postal_code' => '', 'country_code' => ''],
            'lines' => [[
                'item_name' => 'Consulting', 'description' => 'Consulting services, October', 'quantity' => '2', 'unit_code' => 'DAY',
                'unit_price' => '500', 'tax_category' => 'S', 'tax_rate' => '5', 'exempt_code' => '', 'goods_type' => '', 'item_id' => '',
            ]],
        ];
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->gulf->id]);
    }

    private function member(Organization $organization): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'member', 'status' => 'active']);

        return $user;
    }

    /**
     * @return Builder<Customer>
     */
    private function customers(): Builder
    {
        return Customer::acrossTenants();
    }
}
