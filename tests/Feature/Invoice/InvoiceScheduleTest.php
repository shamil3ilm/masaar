<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Enums\ScheduleRunStatus;
use App\Domains\Invoice\Enums\ScheduleStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Models\InvoiceScheduleRun;
use App\Domains\Invoice\Services\ScheduleRunner;
use App\Domains\Invoice\Support\SchedulePeriods;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;

/**
 * Schedules: an organization's invoice to one buyer, made again each
 * period, drafted for review or issued as it is made.
 */
class InvoiceScheduleTest extends TestCase
{
    use MakesSchedules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->makeOrganization();
    }

    public function test_a_due_period_drafts_its_invoice(): void
    {
        $schedule = $this->makeSchedule();

        app(ScheduleRunner::class)->runDue();

        $run = InvoiceScheduleRun::query()->sole();
        $invoice = Invoice::acrossTenants()->with('lines')->sole();

        $this->assertSame(ScheduleRunStatus::Drafted, $run->status);
        $this->assertSame($invoice->id, $run->invoice_id);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame(['2026-11-01', '2026-11-01', '2026-11-30'], [
            $invoice->issue_date->toDateString(), $invoice->supply_date->toDateString(), $invoice->supply_end_date->toDateString(),
        ]);
        $this->assertSame(['1000.00', '150.00', '1150.00'], [(string) $invoice->subtotal, (string) $invoice->tax_amount, (string) $invoice->total]);
        $this->assertSame('system', $invoice->created_via);

        $schedule->refresh();
        $this->assertSame([1, '2026-12-01'], [$schedule->next_period, $schedule->next_run_on->toDateString()]);
    }

    public function test_a_period_not_yet_due_waits(): void
    {
        $this->makeSchedule(['start_date' => '2026-11-02']);

        app(ScheduleRunner::class)->runDue();

        $this->assertSame(0, InvoiceScheduleRun::query()->count());
    }

    /** In arrears: October is billed on 1 November, its period unchanged. */
    public function test_arrears_bill_after_the_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 09:00:00', 'UTC'));
        $schedule = $this->makeSchedule(['start_date' => '2026-10-01', 'bills_in' => 'arrears']);
        $this->assertSame('2026-11-01', $schedule->next_run_on->toDateString());

        app(ScheduleRunner::class)->runDue();
        $this->assertSame(0, InvoiceScheduleRun::query()->count(), 'it billed before the period ended');

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        app(ScheduleRunner::class)->runDue();

        $invoice = Invoice::acrossTenants()->sole();
        $this->assertSame(['2026-11-01', '2026-10-01', '2026-10-31'], [
            $invoice->issue_date->toDateString(), $invoice->supply_date->toDateString(), $invoice->supply_end_date->toDateString(),
        ]);
        $this->assertSame('2026-12-01', $schedule->fresh()->next_run_on->toDateString());
    }

    public function test_auto_issue_issues_each_run(): void
    {
        $this->makeSchedule(['auto_issue' => true]);

        app(ScheduleRunner::class)->runDue();

        $invoice = Invoice::acrossTenants()->sole();
        $this->assertNotSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertSame(ScheduleRunStatus::Issued, InvoiceScheduleRun::query()->sole()->status);
    }

    public function test_missed_periods_are_each_invoiced(): void
    {
        $this->makeSchedule(['start_date' => '2026-09-01']);

        app(ScheduleRunner::class)->runDue();

        $this->assertSame(
            [['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31'], ['2026-11-01', '2026-11-30']],
            Invoice::acrossTenants()->orderBy('supply_date')->get()
                ->map(fn (Invoice $invoice): array => [$invoice->supply_date->toDateString(), $invoice->supply_end_date->toDateString()])->all(),
        );
    }

    public function test_a_counted_schedule_completes(): void
    {
        $schedule = $this->makeSchedule(['start_date' => '2026-10-01', 'occurrences' => 2]);

        app(ScheduleRunner::class)->runDue();

        $schedule->refresh();
        $this->assertSame(ScheduleStatus::Completed, $schedule->status);
        $this->assertNull($schedule->next_run_on);
        $this->assertSame(2, InvoiceScheduleRun::query()->count());
    }

    public function test_an_invalid_invoice_is_refused_upfront(): void
    {
        try {
            $this->makeSchedule([], ['buyer_vat_number' => null, 'buyer_name' => null]);
            $this->fail('the schedule was made');
        } catch (ValidationException $e) {
            $this->assertNotSame([], array_filter(array_keys($e->errors()), fn (string $key): bool => str_starts_with($key, 'invoice.')));
        }

        $this->assertSame(0, InvoiceSchedule::acrossTenants()->count());
    }

    public function test_months_follow_the_calendar(): void
    {
        $periods = new SchedulePeriods(ScheduleFrequency::Monthly, SchedulePeriods::date('2026-01-31'));

        $this->assertSame(
            [['2026-01-31', '2026-02-27'], ['2026-02-28', '2026-03-30'], ['2026-03-31', '2026-04-29']],
            array_map(fn (array $period): array => [$period['start']->toDateString(), $period['end']->toDateString()], $periods->from(0, 3)),
        );
    }

    public function test_terms_are_their_own_dates(): void
    {
        $periods = new SchedulePeriods(ScheduleFrequency::Termly, SchedulePeriods::date('2026-09-01'), terms: [
            ['start_date' => '2026-09-01', 'end_date' => '2026-12-15'],
            ['start_date' => '2027-01-05', 'end_date' => '2027-03-30'],
        ]);

        $this->assertSame(2, $periods->count());
        $this->assertSame('2027-03-30', $periods->at(1)['end']->toDateString());
        $this->assertNull($periods->at(2));
    }

    public function test_an_end_date_ends_the_periods(): void
    {
        $periods = new SchedulePeriods(ScheduleFrequency::Weekly, SchedulePeriods::date('2026-11-02'), SchedulePeriods::date('2026-11-16'));

        $this->assertSame(3, $periods->count());
        $this->assertSame('2026-11-22', $periods->at(2)['end']->toDateString());
    }
}
