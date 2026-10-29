<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Enums\InstalmentModel;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\ScheduleRunStatus;
use App\Domains\Invoice\Enums\ScheduleStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Models\InvoiceScheduleRun;
use App\Domains\Invoice\Support\SchedulePeriods;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Support\CountryClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Makes each schedule's due invoices: every period whose first day has come
 * on the organization's country's clock.
 *
 * Idempotent by construction. A period is claimed by inserting its run row,
 * unique per schedule and period, and its invoice is drafted under that
 * row's lock in the same transaction that marks it drafted - so the hour run
 * twice, two replicas at once, or a retry after a crash each find the period
 * taken or done, and no period is ever invoiced twice. A schedule moves on
 * by compare-and-set on its next period, so two runners never skip one.
 *
 * "Today" is a civil date, read on the organization's clock from
 * Carbon::now() (CountryClock): at 21:30 UTC the Kingdom is already on the
 * next day, and a period starting then is due. The invoice is dated that
 * day, and issuing dates it again to the day it is issued.
 */
class ScheduleRunner
{
    /** Tries a run gets before it is given up on and its administrators told. */
    public const MAX_ATTEMPTS = 5;

    /** The wait before the first retry, doubled for each one after it. */
    public const RETRY_MINUTES = 15;

    public function __construct(
        private readonly ScheduleTemplates $templates,
        private readonly InvoiceDrafter $drafter,
        private readonly ComplianceEngine $engine,
        private readonly TenantResolver $tenant,
        private readonly Access $access,
        private readonly ScheduleAlerts $alerts,
        private readonly Instalments $instalments,
    ) {}

    /**
     * Every active schedule's due periods, then the failed runs whose retry
     * is due.
     *
     * @return int how many periods were attempted
     */
    public function runDue(): int
    {
        $attempted = $this->retryDue();

        // A day ahead of UTC covers every clock east of it; each schedule is
        // then held to its own organization's today.
        $horizon = Carbon::now('UTC')->addDay()->toDateString();

        InvoiceSchedule::acrossTenants()
            ->where('status', ScheduleStatus::Active->value)
            ->whereNotNull('next_run_on')
            ->where('next_run_on', '<=', $horizon)
            ->orderBy('next_run_on')
            ->lazyById(100)
            ->each(function (InvoiceSchedule $schedule) use (&$attempted): void {
                // As the schedule's organization, held to its rows (TenantScope).
                $attempted += $this->tenant->runAs((string) $schedule->org_id, fn (): int => $this->runSchedule($schedule));
            });

        return $attempted;
    }

    /**
     * The runs that failed and are due another try: drafted again, or for
     * a run whose draft exists and was to be issued, issued again. A
     * paused or cancelled schedule's wait; a completed one's last periods
     * are still owed.
     *
     * @return int how many were tried
     */
    public function retryDue(): int
    {
        $tried = 0;

        InvoiceScheduleRun::query()
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->whereHas('schedule', fn ($query) => $query->withoutGlobalScopes()->whereIn('status', [ScheduleStatus::Active->value, ScheduleStatus::Completed->value]))
            ->orderBy('next_attempt_at')
            ->lazyById(100)
            ->each(function (InvoiceScheduleRun $run) use (&$tried): void {
                $schedule = InvoiceSchedule::acrossTenants()->find($run->schedule_id);
                $organization = $schedule === null ? null : Organization::query()->find($schedule->org_id);

                if ($schedule !== null && $organization !== null) {
                    $this->tenant->runAs((string) $organization->id, fn () => $this->attempt($schedule, $run, $organization, CountryClock::now($organization->country)->toDateString()));
                    $tried++;
                }
            });

        return $tried;
    }

    /**
     * One schedule's due periods, the earliest first.
     *
     * @return int how many periods were attempted
     */
    public function runSchedule(InvoiceSchedule $schedule): int
    {
        $organization = Organization::query()->find($schedule->org_id);

        if ($organization === null) {
            return 0;
        }

        $today = CountryClock::now($organization->country)->toDateString();
        $periods = SchedulePeriods::of($schedule);
        $attempted = 0;

        while (($schedule = $this->current($schedule)) !== null
            && $schedule->status === ScheduleStatus::Active
            && $schedule->next_period !== null
            && $schedule->next_run_on !== null
            && $schedule->next_run_on->toDateString() <= $today) {
            $index = $schedule->next_period;
            $period = $periods->at($index);

            if ($period !== null) {
                $run = $this->claim($schedule, $index, $period);

                if (! $run->status->isDone()) {
                    $this->attempt($schedule, $run, $organization, $today);
                    $attempted++;
                }
            }

            $this->advance($schedule, $index, $periods);
        }

        return $attempted;
    }

    /**
     * Draft the period's invoice, unless it is already done, and issue it
     * when the schedule says to.
     */
    public function attempt(InvoiceSchedule $schedule, InvoiceScheduleRun $run, Organization $organization, string $today): void
    {
        $draftedNow = false;

        try {
            $invoice = DB::transaction(function () use ($schedule, $run, $organization, $today, &$draftedNow): ?Invoice {
                $locked = InvoiceScheduleRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

                if ($locked->status->isDone()) {
                    return $locked->status === ScheduleRunStatus::Drafted ? $this->invoiceOf($locked) : null;
                }

                // A delivered plan's later instalment: a payment due against
                // its one invoice, not an invoice of its own.
                if ($this->instalments->isPaymentOnly($schedule, $locked->period)) {
                    $due = $this->instalments->receivable($schedule, $locked->period);
                    $locked->update(['status' => ScheduleRunStatus::Due, 'invoice_id' => $due['invoice']->id, 'amount_due' => $due['amount'], 'error' => null, 'next_attempt_at' => null]);

                    return null;
                }

                $invoice = $this->draft($schedule, $locked, $organization, $today);
                $locked->update([
                    'status' => ScheduleRunStatus::Drafted, 'invoice_id' => $invoice->id, 'error' => null, 'next_attempt_at' => null,
                    // A delivered plan's first instalment is due against the invoice its run made.
                    ...($schedule->instalment_model === InstalmentModel::Delivered
                        ? ['amount_due' => $this->instalments->share((string) $invoice->total, $locked->period, (int) SchedulePeriods::of($schedule)->count())] : []),
                ]);
                $draftedNow = true;

                return $invoice;
            });
        } catch (ValidationException $e) {
            $this->failed($schedule, $run, implode(' ', array_merge(...array_values($e->errors()))));

            return;
        } catch (DomainException $e) {
            // Said for a person: an instalment not issued yet, say.
            $this->failed($schedule, $run, $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $this->failed($schedule, $run, $e->getMessage());

            return;
        }

        $issued = $invoice !== null && $schedule->auto_issue
            && $this->issue($schedule, $run->fresh() ?? $run, $invoice, $organization);

        if ($draftedNow || $issued) {
            InvoiceSchedule::acrossTenants()->whereKey($schedule->id)->update(['last_error' => null]);
        }

        // Announced once, when its invoice is made, as it then stands.
        if ($draftedNow) {
            $this->alerts->created($schedule, $run->fresh() ?? $run, $this->invoiceOf($run->fresh() ?? $run));
        }
    }

    /**
     * The period's run row, made if it is not there yet. The insert is what
     * claims the period: a second one for the same period is ignored.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     */
    private function claim(InvoiceSchedule $schedule, int $index, array $period): InvoiceScheduleRun
    {
        $existing = InvoiceScheduleRun::query()->where('schedule_id', $schedule->id)->where('period', $index)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return InvoiceScheduleRun::query()->create([
                'schedule_id' => $schedule->id,
                'period' => $index,
                'period_start' => $period['start']->toDateString(),
                'period_end' => $period['end']->toDateString(),
                'status' => ScheduleRunStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            return InvoiceScheduleRun::query()->where('schedule_id', $schedule->id)->where('period', $index)->firstOrFail();
        }
    }

    /**
     * Move the schedule past a period, once: only from the period it was at,
     * so a runner that lost the race changes nothing. Past its last period it
     * is completed.
     */
    private function advance(InvoiceSchedule $schedule, int $index, SchedulePeriods $periods): void
    {
        $next = $periods->at($index + 1);

        InvoiceSchedule::acrossTenants()
            ->whereKey($schedule->id)
            ->where('next_period', $index)
            ->update([
                'next_period' => $next === null ? null : $index + 1,
                'next_run_on' => $next === null ? null : $next['runs_on']->toDateString(),
                ...($next === null ? ['status' => ScheduleStatus::Completed->value] : []),
                'updated_at' => now(),
            ]);
    }

    /**
     * The period's invoice, drafted from the template as the country's
     * rules take it.
     *
     * @throws ValidationException when those rules refuse it
     */
    private function draft(InvoiceSchedule $schedule, InvoiceScheduleRun $run, Organization $organization, string $today): Invoice
    {
        $payload = $this->templates->forPeriod($organization, $schedule, $run->period,
            SchedulePeriods::date($run->period_start->toDateString()), SchedulePeriods::date($run->period_end->toDateString()), $today);

        $checked = $this->templates->check($organization, $payload);

        return $this->tenant->runAs((string) $organization->id, function () use ($schedule, $organization, $checked): Invoice {
            $invoice = $this->drafter->draft($checked['draft'], (string) $organization->id, $schedule->branch_id, $checked['country']);

            // The scheduler drafts it, for whoever made the schedule.
            if ($schedule->created_by !== null) {
                $invoice->forceFill(['created_by' => $schedule->created_by])->saveQuietly();
            }

            return $invoice;
        });
    }

    /**
     * Issue a run's draft, as the portal's issue button does: Access first,
     * then the country's issuing, filing when the organization can file.
     * Refused or failed, the draft stays a draft and the run says why.
     */
    private function issue(InvoiceSchedule $schedule, InvoiceScheduleRun $run, Invoice $invoice, Organization $organization): bool
    {
        try {
            $decision = $this->access->decide($organization, null, Feature::IssueInvoices);

            if (! $decision->allowed) {
                throw new DomainException($decision->reason);
            }

            $outcome = $this->tenant->runAs((string) $organization->id, fn () => $this->engine->issue($invoice, $organization, new IssueOptions(
                file: $this->engine->jurisdictionFor($organization)->transmission()->readyToFile($organization),
            )));

            // Another runner issuing the same draft is refused as issued
            // meanwhile: issued is issued.
            if (! $outcome->issued && $this->invoiceOf($run)?->status === InvoiceStatus::Draft) {
                throw new DomainException($outcome->errors[0] ?? __('The invoice could not be issued.'));
            }
        } catch (Throwable $e) {
            $this->failed($schedule, $run, $e->getMessage());

            return false;
        }

        $run->update(['status' => ScheduleRunStatus::Issued, 'error' => null, 'next_attempt_at' => null]);

        return true;
    }

    /**
     * A run that did not finish: the reason kept on it and on its schedule,
     * another try set for later - fifteen minutes, then twice as long each
     * time - and the organization told. After the last try it is left for a
     * person, and its administrators are emailed.
     */
    private function failed(InvoiceSchedule $schedule, InvoiceScheduleRun $run, string $error): void
    {
        $error = mb_substr($error, 0, 500);
        $run = $run->fresh() ?? $run;
        $attempts = $run->attempts + 1;
        $willRetry = $attempts < self::MAX_ATTEMPTS;

        $run->update([
            // A drafted run whose issuing failed keeps its invoice.
            'status' => $run->status === ScheduleRunStatus::Drafted ? ScheduleRunStatus::Drafted : ScheduleRunStatus::Failed,
            'attempts' => $attempts,
            'error' => $error,
            'next_attempt_at' => $willRetry ? now()->addMinutes(self::RETRY_MINUTES * 2 ** ($attempts - 1)) : null,
        ]);

        InvoiceSchedule::acrossTenants()->whereKey($schedule->id)->update(['last_error' => $error, 'updated_at' => now()]);

        $this->alerts->failed($schedule, $run, $willRetry);
    }

    /** The schedule as it stands now, whoever the tenant of the moment is. */
    private function current(InvoiceSchedule $schedule): ?InvoiceSchedule
    {
        return InvoiceSchedule::acrossTenants()->find($schedule->id);
    }

    private function invoiceOf(InvoiceScheduleRun $run): ?Invoice
    {
        return $run->invoice_id === null ? null : Invoice::acrossTenants()->find($run->invoice_id);
    }
}
