<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A batch never files an invoice with ZATCA twice.
 *
 * A retried batch, or one that carries the same sale twice, answers the
 * repeats with the invoice already filed: each invoice has one submission,
 * however often it is asked for, and SubmissionGuard refuses a second while
 * one is in flight or accepted. The queue is faked: a simplified invoice is
 * reported from it, and the submission is what is filed.
 */
class BatchFilesOnceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    /** @var array<string, string> */
    private array $credential;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Retail',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'],
            'pcsid' => $keys['certificate'],
            'secret' => 'device-secret',
        ]);

        $issued = License::createWithCredentials([
            'org_id' => $this->acme->id,
            'organization_name' => 'Acme Retail',
            'contact_email' => 'erp@acme.test',
            'tier' => 'enterprise',
        ]);
        $this->credential = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];

        Queue::fake();
    }

    public function test_a_retried_batch_files_nothing_again(): void
    {
        $items = [$this->item(['idempotency_key' => 'sale-1']), $this->item(['idempotency_key' => 'sale-2'])];

        $this->batch($items)->assertCreated();
        $this->assertSame(2, $this->submissions());

        $this->batch($items)->assertCreated();

        $this->assertSame(2, $this->submissions(), 'a retry filed an invoice with ZATCA again');
        $this->assertSame([1, 1], $this->perInvoice());
    }

    /**
     * Sent twice in one batch with no key, the ERP's reference names the
     * sale, and the second answers with the first.
     */
    public function test_a_repeated_sale_files_once(): void
    {
        $response = $this->batch([
            $this->item(['erp_reference_id' => 'SO-77']),
            $this->item(['erp_reference_id' => 'SO-77']),
        ])->assertCreated();

        $this->assertSame($response->json('data.results.0.data.invoice_id'), $response->json('data.results.1.data.invoice_id'));
        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
        $this->assertSame(1, $this->submissions());
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function item(array $fields): array
    {
        return [
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'auto_submit' => true,
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ...$fields,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function batch(array $items): TestResponse
    {
        return $this->withHeaders($this->credential)->postJson('/api/v1/pipeline/batch', [
            'org_id' => $this->acme->id,
            'invoices' => $items,
        ]);
    }

    /**
     * @return list<int> submissions of each invoice
     */
    private function perInvoice(): array
    {
        return InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()
            ->selectRaw('invoice_id, count(*) as filed')
            ->groupBy('invoice_id')
            ->pluck('filed')
            ->map(fn ($filed): int => (int) $filed)
            ->all());
    }

    private function submissions(): int
    {
        return InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()->count());
    }
}
