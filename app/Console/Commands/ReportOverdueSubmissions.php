<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\DeadlineReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Find the submissions that have run out of time, and say so.
 *
 * Every other alert in this platform fires from the code path that failed.
 * That covers the failures that happen; it does not cover the ones that stop
 * happening. A queue worker that dies, a job that exhausts its retries at
 * three in the morning, a submission left in flight because the process was
 * killed between the API answering and the row being written - none of those
 * raise anything, because nothing is running to raise it.
 *
 * ZATCA gives a simplified invoice twenty-four hours from issuance to be
 * reported. That deadline is a legal obligation, it passes silently, and the
 * only way to notice is to go looking. So this goes looking, on a schedule.
 *
 * Two populations, reported apart because they need different responses:
 *
 *   Overdue - past the deadline. The obligation is already missed; what is
 *   left is to report it late and to know which invoices were affected.
 *
 *   At risk - inside the deadline but past the warning threshold. Still
 *   recoverable, and the only one of the two where an alert can prevent
 *   rather than merely record.
 *
 * Standard invoices are excluded. They are cleared before they are issued,
 * so an unsubmitted one has not been given to a customer and no clock is
 * running on it.
 */
class ReportOverdueSubmissions extends Command
{
    protected $signature = 'fatoora:overdue-submissions
                            {--alert : Raise a critical log event for anything found}
                            {--warn-after= : Hours after issuance to treat as at risk (default: three quarters of the deadline)}';

    protected $description = 'Find simplified submissions that have missed, or are about to miss, the reporting deadline';

    public function handle(DeadlineReport $deadlines): int
    {
        $deadlineHours = (float) config('fatoora.reporting.deadline_hours', 24);
        $warnAfter = $this->option('warn-after') !== null
            ? (float) $this->option('warn-after')
            : $deadlineHours * 0.75;

        $overdue = $deadlines->unreportedOlderThan($deadlineHours);
        $atRisk = $deadlines->unreportedOlderThan($warnAfter)->diffKeys($overdue);

        $this->report('Overdue', $overdue, $deadlineHours);
        $this->report('At risk', $atRisk, $warnAfter);

        if ($overdue->isEmpty() && $atRisk->isEmpty()) {
            $this->info('Nothing is past '.$warnAfter.'h. No submission has missed its deadline.');

            return self::SUCCESS;
        }

        if ($this->option('alert')) {
            // Critical, so it reaches the alerts channel. A deadline that has
            // already passed and one that is about to are both worth waking
            // someone for; the difference is in what they can still do.
            Log::critical('Simplified invoices have missed or are about to miss the ZATCA reporting deadline', [
                'overdue' => $overdue->count(),
                'at_risk' => $atRisk->count(),
                'deadline_hours' => $deadlineHours,
                'oldest_overdue_hours' => $overdue->isEmpty() ? null : $this->ageInHours($overdue->first()),
                'invoice_ids' => $overdue->pluck('invoice_id')->take(20)->all(),
            ]);
        }

        // Non-zero so a scheduled run is visibly failing rather than quietly
        // printing into a log nobody opens.
        return $overdue->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Collection<int|string, InvoiceSubmission>  $submissions
     */
    private function report(string $label, Collection $submissions, float $hours): void
    {
        if ($submissions->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line("<fg=red>{$label}</> - older than {$hours}h and not reported: ".$submissions->count());

        $this->table(
            ['Invoice', 'State', 'Age (h)', 'Retries', 'Last error'],
            $submissions->take(20)->map(fn (InvoiceSubmission $s): array => [
                (string) $s->invoice_id,
                (string) $s->state,
                number_format($this->ageInHours($s), 1),
                (string) $s->retry_count,
                mb_strimwidth((string) $s->last_error, 0, 48, '...'),
            ])->all()
        );
    }

    private function ageInHours(InvoiceSubmission $submission): float
    {
        return $submission->created_at === null
            ? 0.0
            : abs((float) $submission->created_at->diffInMinutes(Carbon::now())) / 60;
    }
}
