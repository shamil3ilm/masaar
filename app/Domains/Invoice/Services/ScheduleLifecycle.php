<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Enums\ScheduleRunStatus;
use App\Domains\Invoice\Enums\ScheduleStatus;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Models\InvoiceScheduleRun;
use App\Domains\Invoice\Support\SchedulePeriods;
use App\Domains\Organization\Models\Organization;
use App\Support\CountryClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a person does to a schedule once it runs: pause and resume it,
 * cancel it, skip its next period, change its prices from the next period
 * on. Each under the schedule's row lock, so it cannot cross a run, and
 * each on the organization's activity record with what it was before.
 *
 * A period that began while the schedule was paused is not billed: resuming
 * marks it skipped and carries on from the first period still to begin.
 * An instalment plan's total was agreed with the buyer, so its periods are
 * not skipped nor its prices changed - cancel it and make another.
 */
class ScheduleLifecycle
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ScheduleTemplates $templates,
    ) {}

    public function pause(InvoiceSchedule $schedule): InvoiceSchedule
    {
        return $this->change($schedule, 'schedule.paused', function (InvoiceSchedule $locked): array {
            $this->expect($locked, [ScheduleStatus::Active], __('Only an active schedule can be paused.'));
            $locked->update(['status' => ScheduleStatus::Paused]);

            return [];
        });
    }

    public function resume(InvoiceSchedule $schedule, Organization $organization): InvoiceSchedule
    {
        return $this->change($schedule, 'schedule.resumed', function (InvoiceSchedule $locked) use ($organization): array {
            $this->expect($locked, [ScheduleStatus::Paused], __('Only a paused schedule can be resumed.'));

            $today = CountryClock::now($organization->country)->toDateString();
            $periods = SchedulePeriods::of($locked);
            $index = $locked->next_period;
            $skipped = [];

            while ($index !== null && ($period = $periods->at($index)) !== null && $period['runs_on']->toDateString() < $today) {
                $this->skipRun($locked, $index, $period);
                $skipped[] = $period['start']->toDateString();
                $index++;
            }

            $this->moveTo($locked, $index ?? 0, $periods, ScheduleStatus::Active);

            return ['skipped_while_paused' => $skipped];
        });
    }

    public function cancel(InvoiceSchedule $schedule): InvoiceSchedule
    {
        return $this->change($schedule, 'schedule.cancelled', function (InvoiceSchedule $locked): array {
            $this->expect($locked, [ScheduleStatus::Active, ScheduleStatus::Paused], __('This schedule has already ended.'));
            $locked->update(['status' => ScheduleStatus::Cancelled, 'next_period' => null, 'next_run_on' => null]);

            return [];
        });
    }

    /** Leave the next period unbilled, and carry on with the one after. */
    public function skip(InvoiceSchedule $schedule): InvoiceSchedule
    {
        return $this->change($schedule, 'schedule.skipped', function (InvoiceSchedule $locked): array {
            $this->expect($locked, [ScheduleStatus::Active, ScheduleStatus::Paused], __('This schedule has already ended.'));

            if ($locked->instalment_model !== null) {
                throw new DomainException(__('An instalment is not skipped: the plan\'s total was agreed. Cancel the plan and make another.'));
            }

            $periods = SchedulePeriods::of($locked);
            $index = (int) $locked->next_period;
            $period = $periods->at($index) ?? throw new DomainException(__('This schedule has no period left to skip.'));

            $this->skipRun($locked, $index, $period);
            $this->moveTo($locked, $index + 1, $periods, $locked->status);

            return ['period' => $index, 'period_start' => $period['start']->toDateString(), 'period_end' => $period['end']->toDateString()];
        });
    }

    /**
     * New prices for the template's lines, by line, from the next period
     * on: invoices already made keep theirs. Checked by the country's
     * rules as the next run would draft it.
     *
     * @param  list<string>  $prices  one per line, in the template's order
     *
     * @throws ValidationException
     */
    public function reprice(InvoiceSchedule $schedule, Organization $organization, array $prices): InvoiceSchedule
    {
        return $this->change($schedule, 'schedule.repriced', function (InvoiceSchedule $locked) use ($organization, $prices): array {
            $this->expect($locked, [ScheduleStatus::Active, ScheduleStatus::Paused], __('This schedule has already ended.'));

            if ($locked->instalment_model !== null) {
                throw new DomainException(__('An instalment plan\'s total was agreed. Cancel the plan and make another.'));
            }

            $template = (array) $locked->template;
            $lines = array_values((array) ($template['lines'] ?? []));

            if (count($prices) !== count($lines)) {
                throw ValidationException::withMessages(['prices' => __('Give a price for each of the schedule\'s :count lines.', ['count' => count($lines)])]);
            }

            $before = array_map(fn (array $line): string => (string) ($line['unit_price'] ?? ''), $lines);
            $template['lines'] = array_map(fn (array $line, string $price): array => [...$line, 'unit_price' => $price], $lines, array_map('strval', $prices));

            $period = SchedulePeriods::of($locked)->at((int) $locked->next_period) ?? throw new DomainException(__('This schedule has no period left to bill.'));
            $this->templates->check($organization, $this->templates->dated($organization, $this->templates->withBuyer($organization, $locked->customer_id, $template), $locked->frequency,
                CountryClock::now($organization->country)->toDateString(), $period['start'], $period['end'], $locked->due_days));

            $locked->update(['template' => $template]);

            return ['from_period' => (int) $locked->next_period, 'prices_before' => $before, 'prices' => array_map('strval', $prices)];
        });
    }

    /**
     * Lock the schedule, make the change, and record it with the schedule
     * as it was and as it is.
     *
     * @param  callable(InvoiceSchedule): array<string, mixed>  $change  returns what the record adds
     */
    private function change(InvoiceSchedule $schedule, string $action, callable $change): InvoiceSchedule
    {
        return DB::transaction(function () use ($schedule, $action, $change): InvoiceSchedule {
            $locked = InvoiceSchedule::withoutTenantScope(fn (): InvoiceSchedule => InvoiceSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail());
            $before = $this->state($locked);
            $detail = $change($locked);

            $this->audit->logChange($action, 'InvoiceSchedule', (string) $locked->id, $before, $this->state($locked->refresh()), $detail,
                organizationId: (string) $locked->org_id);

            return $locked;
        });
    }

    /**
     * A period's run, marked skipped: made if the runner has not reached
     * it, or turned skipped if it has and made no invoice.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     */
    private function skipRun(InvoiceSchedule $schedule, int $index, array $period): void
    {
        $run = InvoiceScheduleRun::query()->where('schedule_id', $schedule->id)->where('period', $index)->lockForUpdate()->first()
            ?? new InvoiceScheduleRun(['schedule_id' => $schedule->id, 'period' => $index]);

        if ($run->exists && $run->invoice_id !== null) {
            throw new DomainException(__('This period is already invoiced.'));
        }

        $run->fill([
            'period_start' => $period['start']->toDateString(),
            'period_end' => $period['end']->toDateString(),
            'status' => ScheduleRunStatus::Skipped,
            'next_attempt_at' => null,
        ])->save();
    }

    private function moveTo(InvoiceSchedule $schedule, int $index, SchedulePeriods $periods, ScheduleStatus $status): void
    {
        $next = $periods->at($index);

        $schedule->update([
            'status' => $next === null ? ScheduleStatus::Completed : $status,
            'next_period' => $next === null ? null : $index,
            'next_run_on' => $next === null ? null : $next['runs_on']->toDateString(),
        ]);
    }

    /**
     * @param  list<ScheduleStatus>  $allowed
     */
    private function expect(InvoiceSchedule $schedule, array $allowed, string $refusal): void
    {
        if (! in_array($schedule->status, $allowed, true)) {
            throw new DomainException($refusal);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function state(InvoiceSchedule $schedule): array
    {
        return [
            'status' => $schedule->status->value,
            'next_period' => $schedule->next_period,
            'next_run_on' => $schedule->next_run_on?->toDateString(),
        ];
    }
}
