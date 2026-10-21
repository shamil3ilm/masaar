<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

/**
 * Where a submission to ZATCA is, and the only moves it may make.
 *
 * The values are the strings invoice_submissions.state has always held (its
 * enum column, 0140), so no row changes. SubmissionLedger is the one writer:
 * it checks every move against next() and refuses the rest, and the model
 * checks again for anything that writes the row some other way.
 *
 * The filing model, end to end. Three records move, each on its own track:
 *
 * 1. The invoice (InvoiceStatus, invoices.status):
 *
 *      draft    -> issued                 IssuingLifecycle (InvoiceDrafter creates drafts)
 *      issued   -> accepted | rejected    InvoiceVerdict, on a verdict only
 *      rejected -> accepted | rejected    InvoiceVerdict, a retried or re-answered filing
 *      accepted                           final; a late answer leaves it as it is
 *
 *    A draft that is thrown away is deleted, not cancelled: ZATCA has no
 *    cancellation, and an issued invoice is corrected by a note. "Cleared"
 *    and "reported" are not invoice statuses; they are this submission's
 *    state and the authority's own words in zatca_response. An answer that
 *    judged nothing (a 401, 404, 409, 413, 5xx, a timeout) leaves the
 *    invoice issued: "held" is the submission failed with ZATCA_NOT_PROCESSED,
 *    "paused" is the device (filing_paused_at, FilingPause) after
 *    ZATCA_UNAUTHORIZED, and neither is a state of its own.
 *
 * 2. The submission (this enum, invoice_submissions.state). Writers:
 *
 *      created queued | pending_submission   SubmissionTracker (via SubmissionLedger::open)
 *      -> pending_submission                 SubmissionLedger::claim: the job, a retry
 *      -> submitted                          just before the request: tracker, job
 *      -> cleared | reported | warning
 *         | rejected | failed | submitted    SubmissionLedger::recordResponse, from
 *                                            submitted, or from a queued or pending
 *                                            row the offline drain answered for
 *      -> failed                             an exception: tracker, job; the queue
 *                                            giving up (job failed()); a stale row
 *                                            recovered (RecoverSubmissions)
 *      -> queued                             the offline fallback taking it over
 *                                            (recordOffline); a lost job requeued
 *      -> cancelled                          SubmissionTracker::cancel, before sending
 *
 *    submitted -> submitted is a clearance ZATCA has not decided yet. The
 *    reconciliation hold: a submission sent whose answer was lost (the
 *    queue gave up, or the answer could not be written) stays submitted,
 *    and nothing sends from it - RecoverSubmissions resends a report from
 *    there, never a clearance. A clearance that timed out after it may have
 *    arrived is recorded failed and its job stops without a retry.
 *    cleared, reported, warning and cancelled are final, and the model
 *    refuses any change to such a row.
 *
 * 3. Two work records, not filing states, kept here for the whole picture:
 *    the offline queue item (OfflineQueue: pending -> processing ->
 *    completed | pending | failed, pending -> cancelled; each move a
 *    conditional update). A module that files through a network instead
 *    keeps the network document's own table, on two tracks (delivery and
 *    reporting), where a document issued before e-invoicing started is
 *    not_transmitted and has no exit.
 */
enum SubmissionState: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case PendingSubmission = 'pending_submission';
    case Submitted = 'submitted';
    case Cleared = 'cleared';
    case Reported = 'reported';
    case Warning = 'warning';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * The states a submission may be created in.
     *
     * @return list<self>
     */
    public static function initial(): array
    {
        return [self::Queued, self::PendingSubmission];
    }

    /**
     * The states this one may move to.
     *
     * @return list<self>
     */
    public function next(): array
    {
        $answers = [self::Submitted, self::Cleared, self::Reported, self::Warning, self::Rejected, self::Failed];

        return match ($this) {
            self::Draft => [self::Queued, self::PendingSubmission, self::Failed, self::Cancelled],
            // Re-stamped when its job is lost; answered by the offline drain.
            self::Queued => [self::Queued, self::PendingSubmission, self::Cancelled, ...$answers],
            self::PendingSubmission => $answers,
            self::Submitted => $answers,
            // failed -> failed: the queue giving up after the attempt failed.
            self::Failed => [self::Failed, self::PendingSubmission, self::Queued],
            self::Rejected => [self::PendingSubmission, self::Queued],
            self::Cleared, self::Reported, self::Warning, self::Cancelled => [],
        };
    }

    public function allows(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }
}
