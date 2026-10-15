<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * An ERP retrying a timed-out call gets the original outcome.
 *
 * That is what the Idempotency-Key header was documented to do, and it only
 * reached the filing step. A retry first drafted a new invoice from the same
 * body: with the same invoice number that collided with the first one and
 * answered an error, so the ERP learned nothing about the invoice it had
 * created; with a fresh number it was a second invoice for one sale. The
 * moment an integrator relies on this is a timeout, when it cannot know
 * whether the first call landed.
 */
class PipelineIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    /** @var array<string, string> */
    private array $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $secret = 'test-secret-acme';

        License::create([
            'org_id' => $this->acme->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret($secret),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@masaar.test',
            'environment' => 'sandbox',
            'tier' => 'starter',
            'status' => 'active',
            'scopes' => ['invoice.submit', 'compliance.status'],
        ]);

        $this->credential = ['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => $secret];
    }

    public function test_a_retry_returns_the_original(): void
    {
        $first = $this->submit('key-1')->assertSuccessful();
        $retry = $this->submit('key-1')->assertSuccessful();

        $this->assertSame($first->json('data.invoice_id'), $retry->json('data.invoice_id'));
        $this->assertSame(1, $this->invoices());
    }

    /**
     * A key names one request. Reused for a different invoice it is an
     * integrator's bug, and answering with someone else's invoice would hide
     * it.
     */
    public function test_a_reused_key_is_refused(): void
    {
        $this->submit('key-1')->assertSuccessful();

        $this->submit('key-1', ['invoice_number' => 'INV-2'])->assertStatus(422);

        $this->assertSame(1, $this->invoices());
    }

    public function test_a_new_key_drafts_anew(): void
    {
        $this->submit('key-1')->assertSuccessful();
        $this->submit('key-2', ['invoice_number' => 'INV-2'])->assertSuccessful();

        $this->assertSame(2, $this->invoices());
    }

    /**
     * And another tenant's key is another tenant's: keys are per organization.
     */
    public function test_keys_are_per_organization(): void
    {
        $this->submit('key-1')->assertSuccessful();

        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $rival->id,
            'invoice_number' => 'RIVAL-1',
            'type' => 'simplified',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
            'idempotency_key' => 'key-1',
        ]));

        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::where('org_id', $this->acme->id)->count()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    /**
     * A retry is how an ERP recovers from a failed call, so it has to pick up
     * where that call stopped.
     *
     * The replay answered with the stored invoice and no errors. When the
     * first call had drafted but failed to issue, the retry reported a clean
     * success for a draft that held no counter, no hash and no QR - and the
     * ERP, keying off the status code, marked the sale done.
     */
    public function test_a_retry_finishes_a_failed_issue(): void
    {
        $first = $this->submit('key-1')->assertStatus(207);
        $this->assertSame('draft', $first->json('data.status'));

        // What stopped it - the seller's VAT registration and address - is put right.
        $this->acme->forceFill([
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ])->save();

        $retry = $this->submit('key-1')->assertCreated();

        $this->assertSame($first->json('data.invoice_id'), $retry->json('data.invoice_id'));
        $this->assertSame('issued', $retry->json('data.status'));
        $this->assertSame(1, $this->invoices());
    }

    /**
     * Issued, and the filing failed: a retry says so rather than reporting a
     * clean success for an invoice ZATCA never received.
     *
     * The replay answered with the stored invoice and no errors whenever it
     * was no longer a draft, so a 207 on the first call became a 201 on the
     * retry and the ERP marked an unfiled invoice done.
     */
    public function test_a_retry_reports_an_unfiled_invoice(): void
    {
        $this->acme->forceFill([
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ])->save();

        $first = $this->submit('key-1', ['auto_submit' => true])->assertStatus(207);
        $this->assertSame('issued', $first->json('data.status'));

        $retry = $this->submit('key-1', ['auto_submit' => true])->assertStatus(207);

        $this->assertSame($first->json('data.invoice_id'), $retry->json('data.invoice_id'));
        $this->assertNotSame([], $retry->json('data.errors'));
        $this->assertSame(1, $this->invoices());
    }

    private function submit(string $key, array $overrides = []): TestResponse
    {
        return $this->withHeaders([...$this->credential, 'Idempotency-Key' => $key])
            ->postJson('/api/v1/pipeline/submit', array_merge([
                'org_id' => $this->acme->id,
                'invoice_number' => 'INV-1',
                'type' => 'simplified',
                'document_type' => 'invoice',
                'issue_date' => now()->toDateString(),
                'auto_submit' => false,
                'lines' => [[
                    'description' => 'Item',
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
            ], $overrides));
    }

    private function invoices(): int
    {
        return Invoice::withoutTenantScope(fn () => Invoice::count());
    }
}
