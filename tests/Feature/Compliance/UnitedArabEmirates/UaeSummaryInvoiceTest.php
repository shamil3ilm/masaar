<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
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
