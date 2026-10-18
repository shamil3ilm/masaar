<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * An ERP's due date is a due date.
 *
 * The pipeline took an ERP's due_date and stored it as the supply date for a
 * country "with no due date of its own", so a Saudi invoice payable in 30
 * days was filed with a delivery date 30 days away (KSA-5). A supply date is
 * sent as supply_date; due_date is kept as the due date, with the payment
 * terms beside it, which the document now carries (KSA-22).
 */
class DueDateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        License::create([
            'org_id' => $this->acme->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret('test-secret-acme'),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@masaar.test',
            'environment' => 'sandbox',
            'tier' => 'professional',
            'status' => 'active',
            'scopes' => ['invoice.submit', 'compliance.status'],
        ]);
    }

    public function test_a_due_date_is_not_supplied(): void
    {
        $this->submit(['due_date' => '2026-11-30'])->assertSuccessful();

        $invoice = $this->invoice();
        $this->assertNull($invoice->supply_date);
        $this->assertSame('2026-11-30', $invoice->due_date?->toDateString());
        $this->assertStringNotContainsString('2026-11-30', $this->xml($invoice));
    }

    /** What an ERP that meant the supply date sends now. */
    public function test_a_supply_date_is_kept(): void
    {
        $this->submit(['supply_date' => '2026-10-01', 'due_date' => '2026-11-30'])->assertSuccessful();

        $invoice = $this->invoice();
        $this->assertSame(['2026-10-01', '2026-11-30'], [$invoice->supply_date?->toDateString(), $invoice->due_date?->toDateString()]);
        $this->assertMatchesRegularExpression('#<cbc:ActualDeliveryDate[^>]*>2026-10-01</cbc:ActualDeliveryDate>#', $this->xml($invoice));
    }

    public function test_payment_terms_reach_the_document(): void
    {
        $this->submit(['payment_terms' => '30 days net'])->assertSuccessful();

        $xml = $this->xml($this->invoice());

        $this->assertMatchesRegularExpression('#<cac:PayeeFinancialAccount[^>]*>\s*<cbc:PaymentNote[^>]*>30 days net</cbc:PaymentNote>#', $xml);
        $this->assertStringNotContainsString('InstructionNote', $xml);
    }

    public function test_a_due_date_follows_the_issue(): void
    {
        $this->submit(['due_date' => '2026-09-30'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('due_date', 'error.details');
    }

    private function invoice(): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->sole());
    }

    private function xml(Invoice $invoice): string
    {
        return app(DocumentBuilder::class)->generateComplianceData($invoice, $this->acme)['xml'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submit(array $overrides): TestResponse
    {
        return $this->withHeaders(['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => 'test-secret-acme'])
            ->postJson('/api/v1/pipeline/submit', [
                'org_id' => $this->acme->id,
                'invoice_number' => 'ERP-1',
                'type' => 'simplified',
                'document_type' => 'invoice',
                'issue_date' => '2026-10-01',
                'auto_submit' => false,
                'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
                ...$overrides,
            ]);
    }
}
