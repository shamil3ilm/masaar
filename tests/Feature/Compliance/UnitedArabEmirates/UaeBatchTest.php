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
 * A UAE organization's batch: each invoice issued and sent once through the
 * service provider, however often the batch is retried. Nothing of the
 * device chains applies - a UAE invoice has no counter - and a sale time a
 * till sends is not the UAE's moment of issue.
 */
class UaeBatchTest extends TestCase
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
            'tier' => 'enterprise',
        ]);
        $this->credentials = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
    }

    public function test_a_retried_batch_sends_once(): void
    {
        $items = [$this->item('ERP-AE-1', 'sale-1'), $this->item('ERP-AE-2', 'sale-2')];

        $this->batch($items)->assertCreated();
        $retry = $this->batch($items)->assertCreated();

        $this->assertSame([201, 201], array_column($retry->json('data.results'), 'http_status'));
        $this->assertSame(2, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
        $this->assertSame(2, PeppolDocument::query()->count());
        Queue::assertPushed(SendPeppolDocument::class, 2);
    }

    public function test_till_time_is_not_taken(): void
    {
        $this->freezeSecond();

        $this->batch([[...$this->item('ERP-AE-1', 'sale-1'), 'issued_at' => now()->subHours(3)->toIso8601String()]])->assertCreated();

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->sole());
        $this->assertTrue(now()->equalTo($invoice->issued_at), 'a UAE invoice took the till\'s time as its moment of issue');
        $this->assertNull($invoice->icv);
        $this->assertNull($invoice->device_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $reference, string $key): array
    {
        return [
            'idempotency_key' => $key,
            'erp_reference_id' => $reference,
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
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function batch(array $items): TestResponse
    {
        return $this->withHeaders($this->credentials)->postJson('/api/v1/pipeline/batch', [
            'org_id' => $this->gulf->id,
            'invoices' => $items,
        ]);
    }
}
