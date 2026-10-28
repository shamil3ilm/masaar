<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A Saudi supply over a period, as a caller sends it.
 *
 * The drafter and the document have carried KSA-24, the supply end date,
 * since subscriptions were billed - and the Saudi rules never named it, so a
 * period sent to the API lost its end on validation and went out as a
 * single day.
 */
class SupplyPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_the_end_date_is_kept(): void
    {
        $this->api(['supply_date' => '2026-09-01', 'supply_end_date' => '2026-09-30'])->assertCreated();

        $invoice = Invoice::acrossTenants()->sole();
        $this->assertSame(['2026-09-01', '2026-09-30'], [$invoice->supply_date->toDateString(), $invoice->supply_end_date->toDateString()]);
    }

    public function test_one_day_is_a_period(): void
    {
        $this->api(['supply_date' => '2026-09-01', 'supply_end_date' => '2026-09-01'])->assertCreated();
    }

    public function test_an_end_needs_a_start(): void
    {
        $this->api(['supply_end_date' => '2026-09-30'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.supply_date.0', 'A supply end date needs a supply date (BR-KSA-35).')
            ->assertJsonMissingPath('error.details.supply_end_date');
    }

    public function test_the_end_is_not_before_start(): void
    {
        $this->api(['supply_date' => '2026-09-30', 'supply_end_date' => '2026-09-01'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.supply_end_date.0', 'The supply end date cannot be before the supply date (BR-KSA-36).');

        $this->assertSame(0, Invoice::acrossTenants()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function api(array $overrides): TestResponse
    {
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);

        return $this->withToken($token)->postJson('/api/invoices', [
            'invoice_number' => 'INV-1',
            'type' => 'simplified',
            'issue_date' => '2026-10-01',
            'lines' => [['description' => 'Subscription', 'description_ar' => 'اشتراك', 'quantity' => 1, 'unit_price' => 100]],
            ...$overrides,
        ]);
    }
}
