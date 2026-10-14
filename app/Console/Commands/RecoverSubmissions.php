<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Fatoora\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\SubmissionLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Send on the submissions nothing is coming back for.
 *
 * A worker that dies, a job the queue loses, a process killed mid-request -
 * each leaves a submission in flight with no answer on its way. Nothing
 * recovered them: the invoice could never be filed again, because a second
 * submission is refused while one is in flight, and fatoora:overdue-submissions
 * only reported them once the deadline had passed.
 *
 * What is safe depends on how far it got:
 *
 *   queued              nothing sent - the job is dispatched again
 *   pending_submission  claimed, nothing sent: the move to submitted comes
 *                       before the request - marked failed and sent
 *   submitted, report   may have arrived - resent, because ZATCA has been
 *                       observed answering the same report REPORTED twice
 *   submitted, clearance may have been cleared - not resent; logged for
 *                       reconciliation, since clearing a document twice is
 *                       not something to find out in production
 *
 * A duplicate job is harmless: SubmissionLedger::claim() lets one through.
 */
class RecoverSubmissions extends Command
{
    protected $signature = 'fatoora:recover-submissions {--limit=500 : Most submissions to recover in one run}';

    protected $description = 'Resend submissions left in flight with no answer coming';

    public function handle(SubmissionLedger $ledger): int
    {
        $stale = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()
            ->stale()
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get());

        $counts = ['resent' => 0, 'reconcile' => 0];

        foreach ($stale as $submission) {
            if ($submission->state === 'submitted' && $submission->isClearance()) {
                $counts['reconcile']++;

                Log::critical('A clearance was sent and no answer recorded; it needs reconciling before it is resent', [
                    'submission_id' => $submission->id,
                    'invoice_id' => $submission->invoice_id,
                    'org_id' => $submission->org_id,
                    'state_changed_at' => $submission->state_changed_at?->toIso8601String(),
                ]);

                $this->warn("  {$submission->id}: clearance with no answer - reconcile with ZATCA before resending");

                continue;
            }

            if ($submission->state !== 'queued') {
                $ledger->transition($submission, 'failed', 'recovered', [
                    'from' => $submission->state,
                    'reason' => $submission->state === 'submitted'
                        ? 'sent with no answer recorded; a report is safe to resend'
                        : 'claimed and never sent',
                ]);
            }

            ProcessFatooraSubmission::dispatch($submission->fresh());
            $counts['resent']++;
        }

        $this->info("Recovered {$counts['resent']} submission(s); {$counts['reconcile']} clearance(s) need reconciling.");

        return self::SUCCESS;
    }
}
