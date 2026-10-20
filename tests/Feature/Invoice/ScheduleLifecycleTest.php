<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Invoice\Enums\ScheduleRunStatus;
use App\Domains\Invoice\Enums\ScheduleStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Models\InvoiceScheduleRun;
use App\Domains\Invoice\Services\ScheduleRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Pausing, resuming, cancelling, skipping a period and changing the price
 * from the next period on - through the API, each on the activity record.
 */
class ScheduleLifecycleTest extends TestCase
{
    use MakesSchedules;
    use RefreshDatabase;

    private InvoiceSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->makeOrganization();
        $this->schedule = $this->makeSchedule();
    }

    public function test_resuming_skips_what_pause_missed(): void
    {
        $this->api('pause')->assertOk()->assertJsonPath('data.schedule.status', 'paused');
        $this->runOn('2026-11-01');
        $this->assertSame(0, $this->invoices());

        $this->travelTo(CarbonImmutable::parse('2027-01-01 09:00:00', 'UTC'));
        $this->api('resume')->assertOk()->assertJsonPath('data.schedule.next_run_on', '2027-01-01');
        $this->runOn('2027-01-01');

        $this->assertSame(1, $this->invoices());
        $this->assertSame([ScheduleRunStatus::Skipped, ScheduleRunStatus::Skipped, ScheduleRunStatus::Drafted], InvoiceScheduleRun::query()->orderBy('period')->pluck('status')->all());
        $this->assertSame(['2026-11-01', '2026-12-01'], $this->audit('schedule.resumed')->metadata['skipped_while_paused']);
    }

    public function test_cancelled_it_runs_no_more(): void
    {
        $this->api('cancel')->assertOk()->assertJsonPath('data.schedule.status', 'cancelled');
        $this->api('resume')->assertStatus(409);

        $this->runOn('2026-11-01');

        $this->assertSame(0, $this->invoices());
        $this->assertSame(['status' => 'active', 'next_period' => 0, 'next_run_on' => '2026-11-01'], $this->audit('schedule.cancelled')->old_values);
    }

    public function test_a_skipped_period_is_not_billed(): void
    {
        $this->api('skip')->assertOk()->assertJsonPath('data.schedule.next_run_on', '2026-12-01');
        $this->runOn('2026-12-01');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->sole());
        $this->assertSame('2026-12-01', $invoice->supply_date->toDateString());
        $this->assertSame('2026-11-01', $this->audit('schedule.skipped')->metadata['period_start']);
    }

    public function test_a_new_price_applies_from_next(): void
    {
        $this->runOn('2026-11-01');

        $this->withToken($this->token())->putJson('/api/schedules/'.$this->schedule->id.'/prices', ['prices' => ['1200.00']])->assertOk();
        $this->runOn('2026-12-01');

        $this->assertSame(['1000.00', '1200.00'], Invoice::withoutTenantScope(fn () => Invoice::query()->orderBy('supply_date')->pluck('subtotal')->map(fn ($v): string => (string) $v)->all()));
        $this->assertSame(['1000.00'], $this->audit('schedule.repriced')->metadata['prices_before']);
    }

    public function test_one_price_per_line(): void
    {
        $this->withToken($this->token())->putJson('/api/schedules/'.$this->schedule->id.'/prices', ['prices' => ['1.00', '2.00']])
            ->assertUnprocessable()->assertJsonValidationErrors(['prices'], 'error.details');
    }

    public function test_an_instalment_is_never_skipped(): void
    {
        $this->schedule = $this->makeSchedule(['occurrences' => 3, 'instalment_model' => 'periodic']);

        $this->api('skip')->assertStatus(409);
        $this->assertSame(ScheduleStatus::Active, $this->schedule->fresh()->status);
    }

    private function api(string $action): TestResponse
    {
        return $this->withToken($this->token())->postJson('/api/schedules/'.$this->schedule->id.'/'.$action);
    }

    private function runOn(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 09:00:00', 'UTC'));
        app(ScheduleRunner::class)->runDue();
    }

    private function audit(string $action): AuditLog
    {
        return AuditLog::query()->where('action', $action)->where('entity_id', $this->schedule->id)->sole();
    }

    private function invoices(): int
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->count());
    }

    private function token(): string
    {
        return JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);
    }
}
