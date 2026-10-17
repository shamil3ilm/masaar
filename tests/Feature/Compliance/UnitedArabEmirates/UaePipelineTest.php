<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Networks\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /api/v1/pipeline/submit for a UAE organization: the invoice is
 * checked by the UAE's InvoiceRules, drafted with its UAE fields, issued and
 * - with auto_submit - queued for the service provider, in one call.
 */
class UaePipelineTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    /** @var array<string, string> */
    private array $credentials;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $this->gulf = $this->uaeOrganization();

        $issued = License::createWithCredentials([
            'org_id' => $this->gulf->id,
            'organization_name' => 'Gulf Trading LLC',
            'contact_email' => 'erp@gulf.test',
            'tier' => 'professional',
        ]);
        $this->credentials = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
    }

    public function test_pipeline_issues_and_queues_uae_invoice(): void
    {
        $response = $this->submit($this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.errors', [])
            ->assertJsonPath('data.erp_reference_id', 'ERP-AE-1');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->sole());
        $document = PeppolDocument::query()->sole();

        $this->assertSame('issued', $invoice->status->value);
        $this->assertSame(now()->addDays(30)->toDateString(), $invoice->due_date?->toDateString());
        $this->assertSame('AUH', $invoice->jurisdiction_data['buyer_subdivision']);
        $this->assertSame('0235:1007654321', $invoice->jurisdiction_data['buyer_endpoint']);
        $this->assertSame('Consulting', $invoice->lines[0]->item_name);
        $this->assertSame('1050.00', (string) $invoice->total);
        $this->assertSame($invoice->id, $document->invoice_id);
        $this->assertNotNull($document->queued_at);
        $this->assertSame($document->id, $response->json('data.zatca_response.submission_id'));
        Queue::assertPushed(SendPeppolDocument::class, 1);
    }

    public function test_held_back_invoice_is_not_queued(): void
    {
        $this->submit($this->payload(['auto_submit' => false]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'issued');

        $this->assertSame('ready', PeppolDocument::query()->sole()->status);
        $this->assertNull(PeppolDocument::query()->sole()->queued_at);
        Queue::assertNothingPushed();
    }

    public function test_uae_errors_come_in_envelope(): void
    {
        $payload = $this->payload(['currency' => 'USD', 'buyer_subdivision' => 'XYZ']);
        unset($payload['buyer_endpoint']);

        $this->submit($payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.exchange_rate.0', 'An invoice in another currency needs its exchange rate to AED.')
            ->assertJsonPath('error.details.buyer_subdivision.0', 'The emirate is one of AUH, DXB, SHJ, UAQ, FUJ, AJM, RAK.')
            ->assertJsonStructure(['error' => ['details' => ['buyer_endpoint']]]);

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    public function test_uae_line_rules_refuse_rates(): void
    {
        $details = $this->submit($this->payload(['lines' => [[...$this->payload()['lines'][0], 'tax_rate' => 15]]]))
            ->assertUnprocessable()
            ->json('error.details');

        $this->assertSame(['The UAE standard rate is 5%.'], $details['lines.0.tax_rate']);
        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(array $payload): TestResponse
    {
        return $this->withHeaders($this->credentials)->postJson('/api/v1/pipeline/submit', $payload);
    }

    /**
     * A UAE invoice as an ERP sends it: the pipeline's fields beside the
     * UAE's own.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'org_id' => $this->gulf->id,
            'erp_reference_id' => 'ERP-AE-1',
            'invoice_type' => 'standard',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'lines' => [[
                'item_name' => 'Consulting',
                'description' => 'Consulting services, October',
                'quantity' => 2,
                'unit_code' => 'DAY',
                'unit_price' => 500,
                'tax_category' => 'S',
                'tax_rate' => 5,
            ]],
        ], $overrides);
    }
}
