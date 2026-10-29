<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
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
            'contact_email' => 'ops@example.test',
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

    /** A retry says it is one, as every other idempotent route does. */
    public function test_a_retry_says_it_is_replayed(): void
    {
        $this->submit('key-1')->assertSuccessful()->assertHeaderMissing('Idempotent-Replayed');

        $this->submit('key-1')->assertSuccessful()->assertHeader('Idempotent-Replayed', 'true');
        $this->submit('key-2', ['invoice_number' => 'INV-2'])->assertSuccessful()->assertHeaderMissing('Idempotent-Replayed');
    }

    /**
     * A key names one request. Reused for a different invoice it is an
     * integrator's bug, and answering with someone else's invoice would hide
     * it.
     */
    public function test_a_reused_key_is_refused(): void
    {
        $this->submit('key-1')->assertSuccessful();

        $this->submit('key-1', ['invoice_number' => 'INV-2'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, $this->invoices());
    }

    /**
     * Fingerprinted as sent, before the issue date is filled in: a retry
     * after midnight is the same request.
     */
    public function test_retry_after_midnight_is_same(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 20:50:00', 'UTC')); // 23:50 in Riyadh
        $body = ['invoice_number' => null, 'issue_date' => null, 'sold_at' => now()->toIso8601String()];

        $first = $this->submit('key-1', $body)->assertSuccessful();

        $this->travelTo(CarbonImmutable::parse('2026-10-21 21:10:00', 'UTC')); // 00:10, the next day
        $retry = $this->submit('key-1', $body)->assertSuccessful();

        $this->assertSame($first->json('data.invoice_id'), $retry->json('data.invoice_id'));
        $this->assertSame(1, $this->invoices());
    }

    public function test_a_changed_body_is_refused(): void
    {
        $this->submit('key-1', ['invoice_number' => null])->assertSuccessful();

        $this->submit('key-1', ['invoice_number' => null, 'order_reference' => 'PO-2'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, $this->invoices());
    }

    /**
     * Sent without a number, the invoice is numbered at issue - and the
     * retry, which still sends none, is the same request, not a reused key.
     */
    public function test_an_unnumbered_retry_returns_the_original(): void
    {
        // Complete, so the first call issues and the invoice is numbered.
        $this->acme->forceFill([
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ])->save();

        $first = $this->submit('key-1', ['invoice_number' => null])->assertSuccessful();
        $retry = $this->submit('key-1', ['invoice_number' => null])->assertSuccessful();

        $this->assertSame($first->json('data.invoice_id'), $retry->json('data.invoice_id'));
        $this->assertNotNull($first->json('data.invoice_number'), 'the first call did not issue, so nothing was numbered');
        $this->assertSame(1, $this->invoices());
    }

    /**
     * With no key and no number the ERP's reference names the document. The
     * number used to be made from it, so a retry collided; now the number
     * comes from the sequence, and a retry must still not bill twice.
     */
    public function test_an_erp_reference_dedupes_without_key(): void
    {
        $first = $this->submit(null, ['invoice_number' => null, 'erp_reference_id' => 'SO-77'])->assertSuccessful();
        $retry = $this->submit(null, ['invoice_number' => null, 'erp_reference_id' => 'SO-77'])->assertSuccessful();

        $this->assertSame($first->json('data.invoice_id'), $retry->json('data.invoice_id'));
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
        Invoice::acrossTenants()->create([
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
        ]);

        $this->assertSame(1, Invoice::acrossTenants()->where('org_id', $this->acme->id)->count());
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

    private function submit(?string $key, array $overrides = []): TestResponse
    {
        return $this->withHeaders([...$this->credential, ...($key === null ? [] : ['Idempotency-Key' => $key])])
            ->postJson('/api/v1/pipeline/submit', array_merge([
                'org_id' => $this->acme->id,
                'invoice_number' => 'INV-1',
                'type' => 'simplified',
                'document_type' => 'invoice',
                'issue_date' => now()->toDateString(),
                'auto_submit' => false,
                'lines' => [[
                    'description' => 'Item',
                    'description_ar' => 'صنف',
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
            ], $overrides));
    }

    private function invoices(): int
    {
        return Invoice::acrossTenants()->count();
    }
}
