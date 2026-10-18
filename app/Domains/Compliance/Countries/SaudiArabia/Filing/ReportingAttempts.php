<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Audit\Attribution\Origin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The evidence of trying to file a document with ZATCA while it could not
 * be reached.
 *
 * ZATCA's Detailed Guidelines for E-Invoicing ask a taxpayer whose system
 * cannot reach the authority to keep trying at regular intervals and to keep
 * evidence of the attempts - "TP should keep evidence of trying to clear the
 * invoice to ZATCA (e.g. API log)" (p46, p49). Each attempt that got no
 * answer, and the one that finally filed it, is one append-only row of
 * reporting_attempts: when, what happened and why.
 */
class ReportingAttempts
{
    /** ZATCA could not be reached, so the document went to the offline queue. */
    public const UNREACHABLE = 'unreachable';

    /** A retry from the queue got no answer; it will be tried again. */
    public const FAILED = 'failed';

    /** A refusal that trying again cannot change. */
    public const GAVE_UP = 'gave_up';

    /** Reported or cleared. */
    public const FILED = 'filed';

    public function __construct(private readonly Origin $origin) {}

    public function record(string $organizationId, string $invoiceId, string $outcome, ?string $detail = null): void
    {
        DB::table('reporting_attempts')->insert([
            'org_id' => $organizationId,
            'invoice_id' => $invoiceId,
            'attempted_at' => now(),
            'outcome' => $outcome,
            'detail' => $detail === null ? null : mb_substr($detail, 0, 1000),
            // The request, or the job a request queued, that tried.
            ...$this->origin->columns(['request_id']),
        ]);
    }

    /**
     * A document's attempts, oldest first.
     *
     * @return Collection<int, object{attempted_at: string, outcome: string, detail: ?string}>
     */
    public function forInvoice(string $organizationId, string $invoiceId): Collection
    {
        return DB::table('reporting_attempts')
            ->where('org_id', $organizationId)
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->get(['attempted_at', 'outcome', 'detail']);
    }
}
