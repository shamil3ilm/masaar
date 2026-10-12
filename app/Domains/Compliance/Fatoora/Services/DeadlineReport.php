<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Simplified submissions that have missed, or are about to miss, the ZATCA
 * reporting deadline.
 *
 * ZATCA gives a simplified invoice twenty-four hours from issuance to be
 * reported. That deadline is a legal obligation and it passes in silence, so
 * `fatoora:overdue-submissions` goes looking every hour and raises a critical
 * event. That event reaches the alerts channel and Slack, and nowhere a person
 * can go and look. This is the same question asked by a screen.
 *
 * The four conditions below are deliberately identical to the command's:
 *
 *   submission_type = reporting   a standard invoice is cleared before it is
 *                                 issued, so an unsubmitted one has reached
 *                                 nobody and no clock is running on it.
 *   state not accepted            cleared, reported and warning all mean the
 *                                 authority has the document; it is finished,
 *                                 however old the row is.
 *   created_at past the age       what the deadline is measured from.
 *   ordered oldest first          the worst breach is the one at the top.
 *
 * Two populations, kept apart because they need different responses. Overdue
 * is already a missed obligation and what is left is to report it late. At
 * risk is inside the deadline and still recoverable - the only one of the two
 * where looking early changes the outcome - so it is subtracted from the
 * overdue set rather than overlapping it.
 */
class DeadlineReport
{
    /**
     * States in which the authority has the document, so no deadline applies.
     *
     * @see InvoiceSubmission::ACCEPTED_STATES - spelled out rather than taken
     *      from the constant so this stays the command's list exactly.
     */
    private const ACCEPTED = ['reported', 'cleared', 'warning'];

    /**
     * The whole picture the alerts screen renders.
     *
     * Cross-tenant on purpose: the platform console is the one place that
     * reads across taxpayers, and a deadline is owed to the authority whoever
     * the seller is. Within a request TenantScope would otherwise scope these
     * to the viewing admin's tenant - which is none - and the page would be
     * permanently, silently empty.
     *
     * @return array{
     *     overdue: Collection<int, array<string, mixed>>,
     *     at_risk: Collection<int, array<string, mixed>>,
     *     overdue_total: int,
     *     at_risk_total: int,
     *     listing_capped_at: int,
     *     deadline_hours: float,
     *     warning_hours: float,
     * }
     */
    public function breakdown(): array
    {
        $deadlineHours = (float) config('fatoora.reporting.deadline_hours', 24);
        $warningHours = $deadlineHours * 0.75;

        return InvoiceSubmission::withoutTenantScope(function () use ($deadlineHours, $warningHours): array {
            $overdue = $this->unreported($deadlineHours);

            return [
                'overdue' => $this->rows($overdue),
                // diffKeys, so a row past the deadline is reported as overdue
                // and not a second time as merely at risk.
                'at_risk' => $this->rows($this->unreported($warningHours)->diffKeys($overdue)),
                // The real totals, so a capped list cannot understate how
                // much is wrong.
                'overdue_total' => $this->countLate($deadlineHours),
                'at_risk_total' => max(0, $this->countLate($warningHours) - $this->countLate($deadlineHours)),
                'listing_capped_at' => self::MOST_URGENT,
                'deadline_hours' => $deadlineHours,
                'warning_hours' => $warningHours,
            ];
        });
    }

    /**
     * Simplified submissions older than the given age that never reached the
     * authority, keyed by id so the two populations can be subtracted.
     *
     * @return Collection<string, InvoiceSubmission>
     */
    /**
     * Submissions older than the given age that never reached the authority.
     *
     * Public because fatoora:overdue-submissions needs exactly this set, and
     * had its own copy of the query. Two definitions of "late" in a compliance
     * platform is one too many: the hourly alert and the console screen must
     * never be able to disagree about which invoices have missed a legal
     * deadline.
     *
     * Returns the models, keyed by id, because the command prints their state,
     * retries and last error - the mapped rows below are for the view.
     *
     * @return Collection<string, InvoiceSubmission>
     */
    public function unreportedOlderThan(float $hours): Collection
    {
        return $this->unreported($hours);
    }

    private function unreported(float $hours): Collection
    {
        return $this->late($hours)
            ->with(['invoice', 'org'])
            ->orderBy('created_at')
            // Oldest first and capped. A row in a terminal state stays overdue
            // for ever, so this set only grows: unbounded, the page that is
            // meant to be read during an incident is the one that times out
            // during an incident. The count beside it is unlimited, so the
            // cap hides rows, never the scale of the problem.
            ->limit(self::MOST_URGENT)
            ->get()
            ->keyBy('id');
    }

    /**
     * How many rows, at most, are listed. The total is counted separately.
     */
    private const MOST_URGENT = 200;

    /**
     * How many there actually are, however many are shown.
     */
    private function countLate(float $hours): int
    {
        return $this->late($hours)->count();
    }

    /**
     * Reporting submissions older than the given age that never arrived.
     *
     * One definition, used by the listing and by the count, so the two cannot
     * disagree about what "late" means.
     *
     * @return Builder<InvoiceSubmission>
     */
    private function late(float $hours): Builder
    {
        return InvoiceSubmission::query()
            ->where('submission_type', 'reporting')
            ->whereNotIn('state', self::ACCEPTED)
            ->where('created_at', '<=', Carbon::now()->subMinutes((int) round($hours * 60)));
    }

    /**
     * @param  Collection<string, InvoiceSubmission>  $submissions
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Collection $submissions): Collection
    {
        return $submissions->values()->map(fn (InvoiceSubmission $submission): array => [
            'id' => $submission->id,
            // The invoice number is what a taxpayer and the authority both
            // name the document by; the submission id names nothing anyone
            // outside this table can look up.
            'invoice_number' => $submission->invoice?->invoice_number ?? $submission->invoice_id,
            'organization' => $submission->org?->name,
            'age_hours' => $this->ageInHours($submission),
            'state' => $submission->state,
            'retry_count' => (int) $submission->retry_count,
            'last_error' => $submission->last_error,
        ]);
    }

    /**
     * Carbon 3 returns a signed difference, and a row created in the past
     * would otherwise age backwards.
     */
    private function ageInHours(InvoiceSubmission $submission): float
    {
        return $submission->created_at === null
            ? 0.0
            : abs((float) $submission->created_at->diffInMinutes(Carbon::now())) / 60;
    }
}
