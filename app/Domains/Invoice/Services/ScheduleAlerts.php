<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Mail\ScheduleFailedMail;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Models\InvoiceScheduleRun;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * What a schedule's runs tell the organization: each invoice made
 * (schedule.run_created), each run that failed and whether it is tried
 * again (schedule.failed), to its webhooks; a failure on its activity
 * record and in the log; and, once a run has been given up on, an email to
 * its administrators - a period nobody invoiced is money nobody asked for.
 */
class ScheduleAlerts
{
    public function __construct(
        private readonly WebhookService $webhooks,
        private readonly AuditService $audit,
    ) {}

    public function created(InvoiceSchedule $schedule, InvoiceScheduleRun $run, ?Invoice $invoice): void
    {
        $this->webhooks->dispatch((string) $schedule->org_id, 'schedule.run_created', [
            ...$this->fields($schedule, $run),
            'invoice_id' => $invoice?->id,
            'invoice_number' => $invoice?->invoice_number,
            'invoice_status' => $invoice?->status?->value,
            'total' => $invoice === null ? null : (string) $invoice->total,
            'currency' => $invoice?->currency,
        ]);
    }

    public function failed(InvoiceSchedule $schedule, InvoiceScheduleRun $run, bool $willRetry): void
    {
        $fields = [...$this->fields($schedule, $run), 'error' => $run->error, 'attempts' => $run->attempts, 'will_retry' => $willRetry];

        $this->webhooks->dispatch((string) $schedule->org_id, 'schedule.failed', $fields);
        $this->audit->logChange('schedule.run_failed', 'InvoiceSchedule', (string) $schedule->id, null, null, $fields, organizationId: (string) $schedule->org_id);

        if ($willRetry) {
            Log::warning('Schedule run failed; it will be tried again', $fields);

            return;
        }

        Log::error('Schedule run given up on', $fields);
        $this->mailAdministrators($schedule, $run);
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(InvoiceSchedule $schedule, InvoiceScheduleRun $run): array
    {
        return [
            'schedule_id' => $schedule->id,
            'schedule_name' => $schedule->name,
            'org_id' => $schedule->org_id,
            'run_id' => $run->id,
            'period' => $run->period,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
        ];
    }

    private function mailAdministrators(InvoiceSchedule $schedule, InvoiceScheduleRun $run): void
    {
        $organization = Organization::query()->find($schedule->org_id);

        if ($organization === null) {
            return;
        }

        $admins = $organization->users()
            ->wherePivotIn('role', MemberRole::ADMINISTERING)
            ->wherePivot('status', 'active')
            ->where('users.status', 'active')
            ->get();

        // One each, so each is written in its reader's language.
        foreach ($admins as $admin) {
            try {
                Mail::to($admin)->send(new ScheduleFailedMail(
                    scheduleName: (string) $schedule->name,
                    periodStart: $run->period_start->toDateString(),
                    periodEnd: $run->period_end->toDateString(),
                    error: (string) $run->error,
                    url: route('portal.schedules.show', $schedule->id),
                ));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
