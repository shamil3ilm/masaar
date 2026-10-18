<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Domains\Platform\Models\FeatureOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Many invoices in one call, each one its own pipeline request.
 *
 * A web shop or ERP catching up sends its backlog together. One invoice that
 * fails must not take the others with it, so each is validated, licensed,
 * drafted, issued and filed on its own, under its own Idempotency-Key, and
 * answered on its own line of the result.
 */
class PipelineBatchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    /** @var array<string, string> */
    private array $credential;

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

        $this->licence(['simplified_invoices']);
    }

    public function test_each_item_stands_alone(): void
    {
        $response = $this->batch([
            $this->item('INV-1', 'sale-1'),
            [...$this->item('INV-2', 'sale-2'), 'lines' => []],
            $this->item('INV-3', 'sale-3'),
        ])->assertStatus(207);

        $this->assertSame([201, 422, 201], array_column($response->json('data.results'), 'http_status'));
        $this->assertSame(['total' => 3, 'succeeded' => 2, 'failed' => 1], $response->json('data.summary'));
        $this->assertSame('issued', $response->json('data.results.0.data.status'));
        $this->assertNull($response->json('data.results.1.data'));
        $this->assertArrayHasKey('lines', $response->json('data.results.1.error.details'));
        $this->assertSame(2, $this->invoices());
    }

    public function test_all_good_is_created(): void
    {
        $this->batch([$this->item('INV-1', 'sale-1'), $this->item('INV-2', 'sale-2')])
            ->assertCreated()
            ->assertJsonPath('data.summary.failed', 0);
    }

    public function test_a_retried_batch_drafts_nothing_new(): void
    {
        $items = [$this->item('INV-1', 'sale-1'), $this->item('INV-2', 'sale-2')];

        $first = $this->batch($items)->assertCreated();
        $retry = $this->batch($items)->assertCreated();

        $this->assertSame(
            array_column(array_column($first->json('data.results'), 'data'), 'invoice_id'),
            array_column(array_column($retry->json('data.results'), 'data'), 'invoice_id'),
        );
        $this->assertSame(2, $this->invoices());
    }

    /**
     * The licence is asked about each document, as the single endpoint asks
     * about its one: a starter licence files simplified invoices only.
     */
    public function test_each_item_is_licensed(): void
    {
        $response = $this->batch([
            $this->item('INV-1', 'sale-1'),
            [...$this->item('INV-2', 'sale-2'), 'type' => 'standard', 'buyer_name' => 'Buyer Co', 'buyer_vat_number' => '300000000000003'],
        ])->assertStatus(207);

        $this->assertSame([201, 403], array_column($response->json('data.results'), 'http_status'));
        $this->assertSame(1, $this->invoices());
    }

    public function test_too_many_items_are_refused(): void
    {
        config(['pipeline.batch.max_items' => 2]);

        $this->batch([$this->item('INV-1'), $this->item('INV-2'), $this->item('INV-3')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoices'], 'error.details');

        $this->assertSame(0, $this->invoices());
    }

    public function test_keys_are_distinct_within_a_batch(): void
    {
        $this->batch([$this->item('INV-1', 'sale-1'), $this->item('INV-2', 'sale-1')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoices.1.idempotency_key'], 'error.details');
    }

    /**
     * No plan restricts batching while allowances are unlimited: a starter
     * licence without the old tier-only feature batches. A platform admin
     * can switch it off for one customer.
     */
    public function test_batching_is_each_customers_switch(): void
    {
        License::withoutGlobalScopes()->update(['features' => ['simplified_invoices']]);

        $this->batch([$this->item('INV-1')])->assertCreated();

        app(FeatureOverrides::class)->set((string) $this->acme->id, Feature::BatchSubmission, FeatureOverride::OFF, 'Paused while the integration is fixed', null);

        $this->batch([$this->item('INV-2')])->assertForbidden();
        $this->assertSame(1, $this->invoices());
    }

    public function test_another_organization_is_refused(): void
    {
        $this->withHeaders($this->credential)->postJson('/api/v1/pipeline/batch', [
            'org_id' => Organization::create(['name' => 'Rival', 'country' => 'SA'])->id,
            'invoices' => [$this->item('INV-1')],
        ])->assertForbidden();
    }

    /**
     * @param  list<string>  $features
     */
    private function licence(array $features): void
    {
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
            'features' => $features,
        ]);

        $this->credential = ['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => $secret];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $number, ?string $key = null): array
    {
        return [
            'idempotency_key' => $key,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'auto_submit' => false,
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 100]],
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

    private function invoices(): int
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $this->acme->id)->count());
    }
}
