<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Auth\Models\User;
use App\Domains\Books\Models\DeemedSupply;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The UAE's special supplies: a summary tax invoice and a contract stage.
 *
 * A UAE summary tax invoice (Executive Regulation Art 59(6)): one tax
 * invoice for the month's deliveries, flagged as a summary (BTAE-02
 * 00010000) with its invoicing period (IBR-138-AE), due within 14 days of
 * the month's end (Art 59(13)(b)).
 *
 * Worked example: two deliveries in October, 3 x 200.00 and 1 x 400.00:
 * 1,000.00 + VAT 5% 50.00 = 1,050.00, to be issued by 14 November.
 */
class UaeSummaryInvoiceTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        $this->gulf = $this->uaeOrganization();
        License::create([
            'org_id' => $this->gulf->id, 'api_key' => 'cp_test_uae_summary', 'api_secret_hash' => License::hashSecret('secret-uae'),
            'organization_name' => 'Gulf', 'contact_email' => 'ops@gulf.test', 'environment' => 'sandbox', 'tier' => 'enterprise',
            'status' => 'active', 'scopes' => ['invoice.read', 'invoice.submit'],
        ]);
        $this->customer = new Customer([
            'org_id' => $this->gulf->id, 'name' => 'Noor Electronics LLC', 'vat_number' => '100765432100003',
            'street' => 'Khalifa Street', 'city' => 'Abu Dhabi', 'country_code' => 'AE', 'subdivision' => 'AUH', 'peppol_endpoint' => '0235:1007654321',
        ]);
        Customer::withoutTenantScope(fn () => $this->customer->save());
    }

    public function test_summary_is_flagged_with_its_period(): void
    {
        $this->deliver('2026-10-05', 3, '200.00')->assertCreated();
        $this->deliver('2026-10-20', 1, '400.00')->assertCreated();

        $response = $this->api()->postJson('/api/v1/invoices/summary', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'issue_date' => '2026-11-04']);
        $this->assertSame(201, $response->status(), (string) $response->getContent());
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($response->json('data.invoice.id')));

        $this->assertSame('00010000', $invoice->jurisdiction_data['transaction_flags']);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$invoice->supply_date->toDateString(), $invoice->supply_end_date->toDateString()]);
        $this->assertSame(['1000.00', '50.00', '1050.00'], [(string) $invoice->subtotal, (string) $invoice->tax_amount, (string) $invoice->total]);
        $this->assertSame('2026-11-14', $response->json('data.issue_by'));
    }

    /**
     * A contract stage on a UAE tax invoice: the continuous-supply flag
     * (BTAE-02 00001000), billed net of retention - 40,000 less 5% =
     * 38,000.00 + VAT 1,900.00 (Electronic Invoicing Guidelines v1.1,
     * scenario 5; Appendix 5 §17.2).
     */
    public function test_a_stage_is_a_continuous_supply(): void
    {
        $contract = $this->api()->postJson('/api/v1/contracts', [
            'customer_id' => $this->customer->id, 'reference' => 'DXB-77', 'title' => 'Fit-out', 'value' => '100000', 'retention_percent' => '5',
            'stages' => [['name' => 'Design', 'percent' => '40'], ['name' => 'Build', 'percent' => '60']],
        ]);
        $this->assertSame(201, $contract->status(), (string) $contract->getContent());
        $stage = $contract->json('data.statement.stages.0.id');

        $response = $this->api()->postJson('/api/v1/contracts/'.$contract->json('data.contract.id')."/stages/{$stage}/invoice", ['issue_date' => '2026-10-10']);
        $this->assertSame(201, $response->status(), (string) $response->getContent());
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($response->json('data.invoice.id')));

        $this->assertSame('00001000', $invoice->jurisdiction_data['transaction_flags']);
        $this->assertSame(['38000.00', '1900.00'], [(string) $invoice->subtotal, (string) $invoice->tax_amount]);
        $this->assertSame('2027-10-10', $response->json('data.statement.retention_due_by'));
    }

    /**
     * A disbursement line is refused on a UAE invoice: the Decree-Law and
     * Executive Regulation texts at hand set no conditions for one.
     */
    public function test_a_disbursement_is_not_offered(): void
    {
        $response = $this->api()->postJson('/api/v1/invoices', [
            'issue_date' => '2026-10-10', 'due_date' => '2026-11-09', 'payment_means_code' => '30', 'disbursements_confirmed' => true,
            'buyer_name' => 'Noor Electronics LLC', 'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'], 'buyer_endpoint' => '0235:1007654321', 'buyer_subdivision' => 'AUH',
            'lines' => [['item_name' => 'Visa fee paid for you', 'quantity' => 1, 'unit_price' => 300, 'tax_category' => 'O', 'kind' => 'disbursement']],
        ]);

        $this->assertSame(422, $response->status(), (string) $response->getContent());
        $response->assertJsonValidationErrors('lines.0.kind', 'error.details');
    }

    /**
     * Deemed supplies under the AED 2,000 output-tax cap over 12 months
     * (Decree-Law Art 12(5); Executive Regulation Art 5(2)(a)): equipment
     * of 30,000.00 taken for private use owes 1,500.00, within the cap, so
     * nothing; another 20,000.00 owes 1,000.00, which takes the total to
     * 2,500.00 - only the 500.00 past the cap is payable, on 10,000.00, on a
     * kept tax invoice flagged as a deemed supply (BTAE-02 01000000).
     */
    public function test_deemed_supplies_pay_past_the_cap(): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->gulf->id, ['role' => 'admin', 'status' => 'active']);
        $record = fn (string $value) => $this->actingAs($user)->post(route('portal.books.deemed-supplies.store'), [
            'kind' => 'own_use', 'description' => 'Equipment taken for private use', 'supplied_on' => '2026-10-05', 'value' => $value,
        ]);

        $record('30000');
        $record('20000');

        $rows = DeemedSupply::withoutTenantScope(fn () => DeemedSupply::query()->orderBy('id')->get());
        $this->assertSame([DeemedSupply::RELIEVED, DeemedSupply::TAXED], $rows->pluck('outcome')->all());
        $this->assertSame(['10000.00', '500.00'], [(string) $rows[1]->taxable_value, (string) $rows[1]->tax_amount]);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($rows[1]->invoice_id));
        $this->assertSame(['01000000', 'DXB', '10500.00'], [$invoice->jurisdiction_data['transaction_flags'], $invoice->jurisdiction_data['buyer_subdivision'], (string) $invoice->total]);
    }

    private function deliver(string $date, int $quantity, string $price): TestResponse
    {
        return $this->api()->postJson('/api/v1/delivery-notes', [
            'customer_id' => $this->customer->id, 'delivered_on' => $date,
            'lines' => [['description' => 'LED panel', 'quantity' => $quantity, 'unit_price' => $price]],
        ]);
    }

    private function api(): static
    {
        return $this->withHeaders(['X-API-Key' => 'cp_test_uae_summary', 'X-API-Secret' => 'secret-uae']);
    }
}
