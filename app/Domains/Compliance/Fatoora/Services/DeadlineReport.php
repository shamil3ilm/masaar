<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
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
    private function unreported(float $hours): Collection
    {
        return InvoiceSubmission::query()
            ->with(['invoice', 'org'])
            ->where('submission_type', 'reporting')
            ->whereNotIn('state', self::ACCEPTED)
            ->where('created_at', '<=', Carbon::now()->subMinutes((int) round($hours * 60)))
            ->orderBy('created_at')
            ->get()
            ->keyBy('id');
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
