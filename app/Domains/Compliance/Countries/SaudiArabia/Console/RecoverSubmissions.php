<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionLedger;
use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
 *   submitted, clearance may have been cleared - reconciled by sending the
 *                       same signed document again (same UUID, hash and
 *                       counter, never re-signed). ZATCA does not reject the
 *                       same invoice submitted twice (Detailed Guidelines, FAQ
 *                       "Can I submit the same invoice twice?"), so its answer
 *                       says whether it is cleared; the stale window is the
 *                       safe delay, about the ~15 minutes the B2B outage
 *                       scenario retries at (Detailed Guidelines, "Failure to
 *                       receive response from ZATCA - B2B scenario")
 *
 * A duplicate job is harmless: SubmissionLedger::claim() lets one through.
 */
class RecoverSubmissions extends Command
{
    protected $signature = 'fatoora:recover-submissions {--limit=500 : Most submissions to recover in one run}';

    protected $description = 'Resend submissions left in flight with no answer coming';

    public function handle(SubmissionLedger $ledger): int
    {
        $stale = InvoiceSubmission::acrossTenants()
            ->stale()
            // The offline drain owns an invoice while it holds a live queue
            // item for it. Recovering the row as well would file it twice.
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('offline_queue')
                ->whereColumn('offline_queue.invoice_id', 'invoice_submissions.invoice_id')
                ->whereIn('offline_queue.state', ['pending', 'processing']))
            // And an invoice the authority has accepted is done, whatever its
            // submission row says.
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('invoices')
                ->whereColumn('invoices.id', 'invoice_submissions.invoice_id')
                ->where('invoices.status', 'accepted'))
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $counts = ['resent' => 0, 'reconcile' => 0, 'errors' => 0];

        foreach ($stale as $submission) {
            if ($submission->isHeld()) {
                try {
                    $this->reconcile($ledger, $submission);
                    $counts['reconcile']++;
                } catch (\Throwable $e) {
                    $counts['errors']++;

                    Log::error('A held clearance could not be sent again; the next run will try again', [
                        'submission_id' => $submission->id,
                        'invoice_id' => $submission->invoice_id,
                        'error' => $e->getMessage(),
                    ]);

                    $this->error("  {$submission->id}: not recovered - {$e->getMessage()}");
                }

                continue;
            }

            try {
                $this->resend($ledger, $submission);
                $counts['resent']++;
            } catch (\Throwable $e) {
                // One row's trouble is not the run's: the rest still go.
                $counts['errors']++;

                Log::error('A stale submission could not be recovered; the next run will try again', [
                    'submission_id' => $submission->id,
                    'invoice_id' => $submission->invoice_id,
                    'error' => $e->getMessage(),
                ]);

                $this->error("  {$submission->id}: not recovered - {$e->getMessage()}");
            }
        }

        $this->info("Recovered {$counts['resent']} submission(s); sent {$counts['reconcile']} held clearance(s) again to reconcile them.");

        return $counts['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Release a held clearance and queue its job, together, as resend() does.
     * The job sends the stored signed XML: it re-signs nothing that already
     * has its counter and hash.
     */
    private function reconcile(SubmissionLedger $ledger, InvoiceSubmission $submission): void
    {
        DB::transaction(function () use ($ledger, $submission): void {
            if (! $ledger->releaseHeld($submission, 'reconcile')) {
                return;
            }

            Log::warning('A held clearance is sent again unchanged to reconcile it with ZATCA', [
                'submission_id' => $submission->id,
                'invoice_id' => $submission->invoice_id,
                'org_id' => $submission->org_id,
            ]);

            ProcessFatooraSubmission::dispatch($submission->fresh());
        });
    }

    /**
     * Re-stamp the row and queue its job, together.
     *
     * Together, because apart they stranded it: a row marked failed whose job
     * then failed to queue is not in flight, so no later run looked at it, and
     * nothing else sends a failed submission. Inside one transaction a queue
     * that refuses the push leaves the row stale, and the next run retries it.
     *
     * A queued row is re-stamped too, though its state does not change. Left
     * with its old timestamp it stayed stale while the job waited behind a
     * backlog, and every run ten minutes apart queued it once more.
     *
     * Deliberately not afterCommit(), here or in reconcile(): pushed after
     * the commit, a refused push would leave a re-stamped failed row that no
     * later run looks at - the stranding this ordering exists to prevent. On
     * the database queue the job row commits with the submission row; on a
     * queue elsewhere, a worker that takes the job first waits on this
     * transaction's row lock in SubmissionLedger::claim() and then sees the
     * committed state.
     */
    private function resend(SubmissionLedger $ledger, InvoiceSubmission $submission): void
    {
        DB::transaction(function () use ($ledger, $submission): void {
            $from = $submission->state;

            $ledger->transition($submission, $from === 'queued' ? 'queued' : 'failed', 'recovered', [
                'from' => $from,
                'reason' => match ($from) {
                    'queued' => 'its job was lost; queued again',
                    'submitted' => 'sent with no answer recorded; a report is safe to resend',
                    default => 'claimed and never sent',
                },
            ]);

            ProcessFatooraSubmission::dispatch($submission->fresh());
        });
    }
}
