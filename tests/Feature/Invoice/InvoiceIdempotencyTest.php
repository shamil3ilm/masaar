<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * POST /api/invoices takes an Idempotency-Key and a branch, as the pipeline
 * does.
 *
 * A till or a web shop that timed out retries, and without a key the retry
 * drafted the sale a second time. And only the pipeline could say which
 * branch a document came from, so an invoice drafted here was always head
 * office's - signed by its certificate and counted in its chain.
 */
class InvoiceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_a_retry_answers_with_the_first(): void
    {
        $first = $this->create(['invoice_number' => 'INV-1'], 'sale-42')->assertCreated();
        $retry = $this->create(['invoice_number' => 'INV-1'], 'sale-42')->assertOk();

        $this->assertSame($first->json('data.invoice.id'), $retry->json('data.invoice.id'));
        $this->assertSame('true', $retry->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, $this->invoices());
    }

    /**
     * Numbered at issue, a retry has no number to compare, and is still the
     * same request.
     */
    public function test_an_unnumbered_retry_is_the_same(): void
    {
        $first = $this->create([], 'sale-43')->assertCreated();

        $this->assertSame($first->json('data.invoice.id'), $this->create([], 'sale-43')->assertOk()->json('data.invoice.id'));
        $this->assertSame(1, $this->invoices());
    }

    public function test_a_reused_key_is_refused(): void
    {
        $this->create(['invoice_number' => 'INV-1'], 'sale-42')->assertCreated();

        $this->create(['invoice_number' => 'INV-2'], 'sale-42')
            ->assertStatus(422)
            ->assertJsonPath('error.details.invoice_number.0', 'This Idempotency-Key was already used for invoice INV-1. Send a new key for a new invoice.');

        $this->assertSame(1, $this->invoices());
    }

    public function test_a_long_key_is_refused(): void
    {
        $this->create([], str_repeat('k', 256))->assertStatus(422);

        $this->assertSame(0, $this->invoices());
    }

    public function test_without_a_key_each_drafts(): void
    {
        $this->create(['invoice_number' => 'INV-1'])->assertCreated();
        $this->create(['invoice_number' => 'INV-2'])->assertCreated();

        $this->assertSame(2, $this->invoices());
    }

    public function test_a_branch_can_be_named(): void
    {
        $branch = $this->branch($this->acme);

        $response = $this->create(['branch_id' => $branch->id])->assertCreated();

        $this->assertSame($branch->id, $response->json('data.invoice.branch_id'));
    }

    public function test_another_organizations_branch_is_refused(): void
    {
        $theirs = $this->branch(Organization::create(['name' => 'Rival', 'country' => 'SA']));

        $this->create(['branch_id' => $theirs->id])->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'error.details');

        $this->assertSame(0, $this->invoices());
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function create(array $fields, ?string $key = null): TestResponse
    {
        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->withToken($this->token)->postJson('/api/invoices', [
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ...$fields,
        ], $headers);
    }

    private function branch(Organization $organization): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $organization->id,
            'name' => 'Jeddah Till',
            'device_serial' => 'EGS-'.uniqid(),
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]));
    }

    private function invoices(): int
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $this->acme->id)->count());
    }
}
