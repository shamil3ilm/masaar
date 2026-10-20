<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Licensing\Models\License;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;

/**
 * Schedules on the partner API: an ERP's licence managing its customer's
 * schedules, scope by scope.
 */
class SchedulePartnerApiTest extends TestCase
{
    use MakesSchedules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->makeOrganization();
    }

    public function test_a_managing_licence_makes_and_reads(): void
    {
        [$headers, $licence] = $this->licence(['schedule.manage']);

        $id = $this->withHeaders($headers)->postJson('/api/v1/schedules', [
            'name' => 'Beta retainer', 'frequency' => 'monthly', 'start_date' => '2026-11-01', 'invoice' => $this->scheduledInvoice(),
        ])->assertCreated()->json('data.schedule.id');

        $this->withHeaders($headers)->getJson('/api/v1/schedules/'.$id)->assertOk()->assertJsonPath('data.schedule.name', 'Beta retainer');
        $this->withHeaders($headers)->postJson("/api/v1/schedules/{$id}/pause")->assertOk()->assertJsonPath('data.schedule.status', 'paused');

        $schedule = InvoiceSchedule::withoutTenantScope(fn () => InvoiceSchedule::query()->findOrFail($id));
        $this->assertSame(['api', $licence->id], [$schedule->created_via, $schedule->license_id]);
    }

    public function test_a_reading_licence_changes_nothing(): void
    {
        [$headers] = $this->licence(['schedule.read']);
        $schedule = $this->makeSchedule();

        $this->withHeaders($headers)->getJson('/api/v1/schedules')->assertOk()->assertJsonPath('data.0.id', $schedule->id);
        $this->withHeaders($headers)->postJson("/api/v1/schedules/{$schedule->id}/cancel")->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/schedules', ['name' => 'X', 'frequency' => 'monthly', 'start_date' => '2026-11-01', 'invoice' => $this->scheduledInvoice()])
            ->assertForbidden();
    }

    public function test_without_the_scope_nothing(): void
    {
        [$headers] = $this->licence(['invoice.submit']);

        $this->withHeaders($headers)->getJson('/api/v1/schedules')->assertForbidden();
    }

    /**
     * @param  list<string>  $scopes
     * @return array{array<string, string>, License}
     */
    private function licence(array $scopes): array
    {
        $issued = License::createWithCredentials(['org_id' => $this->acme->id, 'organization_name' => 'Acme', 'contact_email' => 'erp@example.test', 'tier' => 'enterprise']);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active', 'scopes' => json_encode($scopes)]);

        return [['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']], $issued['license']];
    }
}
