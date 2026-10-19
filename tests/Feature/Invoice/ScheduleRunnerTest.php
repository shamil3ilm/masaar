<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\ScheduleRunStatus;
use App\Domains\Invoice\Mail\ScheduleFailedMail;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Models\InvoiceScheduleRun;
use App\Domains\Invoice\Services\ScheduleRunner;
use App\Domains\Webhook\Jobs\DeliverWebhook;
use App\Domains\Webhook\Models\Webhook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;

/**
 * The runner: a period invoiced once however often or however many times
 * at once it runs, on the organization's clock, and a failure tried again
 * and told.
 */
class ScheduleRunnerTest extends TestCase
{
    use MakesSchedules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->makeOrganization();
    }

    public function test_running_twice_makes_one_invoice(): void
    {
        $this->makeSchedule();

        app(ScheduleRunner::class)->runDue();
        app(ScheduleRunner::class)->runDue();
        Artisan::call('invoices:run-schedules');

        $this->assertSame(1, $this->invoices());
        $this->assertSame(1, InvoiceScheduleRun::query()->count());
    }

    /**
     * A second runner that read the schedule before the first moved it on
     * finds the period claimed and done, and makes nothing.
     */
    public function test_a_claimed_period_is_not_redrafted(): void
    {
        $schedule = $this->makeSchedule();
        app(ScheduleRunner::class)->runDue();
        InvoiceSchedule::withoutTenantScope(fn () => InvoiceSchedule::query()->whereKey($schedule->id)->update(['next_period' => 0, 'next_run_on' => '2026-11-01']));

        app(ScheduleRunner::class)->runDue();

        $this->assertSame(1, $this->invoices());
        $this->assertSame(1, $schedule->fresh()->next_period);
    }

    public function test_one_period_holds_one_run(): void
    {
        $schedule = $this->makeSchedule(['start_date' => '2026-12-01']);
        $row = ['schedule_id' => $schedule->id, 'period' => 0, 'period_start' => '2026-12-01', 'period_end' => '2026-12-31'];
        InvoiceScheduleRun::query()->create($row);

        $this->expectException(UniqueConstraintViolationException::class);
        InvoiceScheduleRun::query()->create($row);
    }

    /**
     * 21:30 UTC is 00:30 in Riyadh: the first of the month has begun there,
     * so its period is due, its invoice is dated that day, and issuing it
     * is not refused as dated in the future.
     */
    public function test_just_after_midnight_riyadh_it_runs(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 20:30:00', 'UTC'));
        $this->makeSchedule(['start_date' => '2026-12-01', 'auto_issue' => true]);

        Artisan::call('invoices:run-schedules');
        $this->assertSame(0, $this->invoices(), 'it ran before midnight in Riyadh');

        $this->travelTo(CarbonImmutable::parse('2026-11-30 21:30:00', 'UTC'));
        $this->assertTrue($this->scheduledTask()->isDue($this->app), 'the scheduler does not run it at 21:30 UTC');

        Artisan::call('invoices:run-schedules');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->sole());
        $this->assertNotSame(InvoiceStatus::Draft, $invoice->status, (string) InvoiceScheduleRun::query()->sole()->error);
        $this->assertSame('2026-12-01', $invoice->issue_date->toDateString());
        $this->assertSame(ScheduleRunStatus::Issued, InvoiceScheduleRun::query()->sole()->status);
    }

    public function test_a_failure_is_retried_and_announced(): void
    {
        $schedule = $this->makeSchedule();
        $this->subscribe(['schedule.failed', 'schedule.run_created']);
        $template = $schedule->template;
        $this->breakTemplate($schedule);

        app(ScheduleRunner::class)->runDue();

        $run = InvoiceScheduleRun::query()->sole();
        $this->assertSame([ScheduleRunStatus::Failed, 1], [$run->status, $run->attempts]);
        $this->assertSame('2026-11-01 09:15:00', $run->next_attempt_at->toDateTimeString());
        $this->assertNotNull($schedule->fresh()->last_error);
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->event === 'schedule.failed' && $job->payload['will_retry'] === true);

        // Not yet due again.
        app(ScheduleRunner::class)->runDue();
        $this->assertSame(1, $run->fresh()->attempts);

        InvoiceSchedule::withoutTenantScope(fn () => InvoiceSchedule::query()->whereKey($schedule->id)->update(['template' => json_encode($template)]));
        $this->travel(16)->minutes();
        app(ScheduleRunner::class)->runDue();

        $this->assertSame(ScheduleRunStatus::Drafted, $run->fresh()->status);
        $this->assertNull($schedule->fresh()->last_error);
        $this->assertSame(1, $this->invoices());
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->event === 'schedule.run_created' && $job->payload['period_start'] === '2026-11-01');
    }

    public function test_the_last_failure_emails_administrators(): void
    {
        Mail::fake();
        $schedule = $this->makeSchedule();
        $this->breakTemplate($schedule);
        app(ScheduleRunner::class)->runDue();

        for ($try = 2; $try <= ScheduleRunner::MAX_ATTEMPTS; $try++) {
            $this->travel(5)->hours();
            app(ScheduleRunner::class)->runDue();
        }

        $run = InvoiceScheduleRun::query()->sole();
        $this->assertSame([ScheduleRunner::MAX_ATTEMPTS, null], [$run->attempts, $run->next_attempt_at]);
        Mail::assertSent(ScheduleFailedMail::class, fn (ScheduleFailedMail $mail): bool => $mail->hasTo($this->admin->email));
        Mail::assertSentCount(1);
    }

    private function breakTemplate(InvoiceSchedule $schedule): void
    {
        InvoiceSchedule::withoutTenantScope(fn () => InvoiceSchedule::query()->whereKey($schedule->id)
            ->update(['template' => json_encode([...$schedule->template, 'lines' => []])]));
    }

    /**
     * @param  list<string>  $events
     */
    private function subscribe(array $events): void
    {
        Webhook::withoutTenantScope(fn () => Webhook::query()->create([
            'org_id' => $this->acme->id, 'url' => 'https://erp.example.test/hook', 'secret' => str_repeat('s', 64), 'events' => $events, 'is_active' => true,
        ]));
    }

    private function scheduledTask(): Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, 'invoices:run-schedules')) {
                return $event;
            }
        }

        $this->fail('invoices:run-schedules is not scheduled');
    }

    private function invoices(): int
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->count());
    }
}
