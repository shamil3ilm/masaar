<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Compliance\Fatoora\Services\InvoiceValidator;
use App\Domains\Invoice\Http\Requests\CreateInvoiceRequest;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\InvoiceDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VAT is reported in SAR, so a foreign-currency invoice records its rate.
 *
 * InvoiceValidator has always enforced BR-KSA-CU-01, reading
 * $invoice->exchange_rate — which was neither a column, a cast, nor a field any
 * request accepted. isset() on it was therefore always false, so the rule could
 * never be satisfied: a non-SAR invoice passed request validation and was then
 * refused at compliance, citing a field the caller had no way to send.
 *
 * The rate is now stored, accepted, and immutable after issue.
 */
class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_foreign_currency_passes_with_a_rate(): void
    {
        $invoice = $this->draft(['currency' => 'USD', 'exchange_rate' => '3.750000']);

        $result = app(InvoiceValidator::class)->validate($invoice->fresh(), $this->org);

        $this->assertNotContains(
            'BR-KSA-CU-01: Foreign currency invoices require exchange_rate to SAR',
            $result['errors'] ?? []
        );
    }

    public function test_foreign_currency_fails_without_a_rate(): void
    {
        $invoice = $this->draft(['currency' => 'USD']);

        $result = app(InvoiceValidator::class)->validate($invoice->fresh(), $this->org);

        $this->assertContains(
            'BR-KSA-CU-01: Foreign currency invoices require exchange_rate to SAR',
            $result['errors'] ?? []
        );
    }

    public function test_the_rate_is_persisted(): void
    {
        $invoice = $this->draft(['currency' => 'USD', 'exchange_rate' => '3.750000']);

        $this->assertSame('3.750000', $invoice->fresh()->exchange_rate);
    }

    /**
     * Requests are refused at the boundary, where the caller can act on the
     * message, rather than after the document has been drafted.
     */
    public function test_request_requires_a_rate(): void
    {
        $rules = (new CreateInvoiceRequest)
            ->merge(['currency' => 'USD'])
            ->rules();

        $this->assertArrayHasKey('exchange_rate', $rules);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draft(array $overrides): Invoice
    {
        return app(InvoiceDrafter::class)->draft(array_merge([
            'invoice_number' => 'FX-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer',
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '100.00']],
        ], $overrides), $this->org->id);
    }
}
