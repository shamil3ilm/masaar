<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Issuing as every country has it: under the organization row's lock a draft
 * takes its number and its moment of issue and becomes Issued. What a country
 * adds - checks, a counter, a signature, a document of its own - arrives
 * through the IssuingJurisdiction it is given, at the points that contract
 * lists.
 */
class IssuingLifecycle
{
    public function __construct(
        private readonly InvoiceNumbering $numbering,
    ) {}

    /**
     * @param  bool  $reissue  Allow a document that is no longer a draft to be
     *                         issued again. Only for a caller completing an
     *                         issuance that did not finish.
     * @return mixed what the jurisdiction's afterIssued() or finished() returned
     */
    public function issue(Invoice $invoice, Organization $organization, IssuingJurisdiction $jurisdiction, bool $reissue = false): mixed
    {
        // Re-issuing rewrites the hash and, through ChainRecorder's
        // updateOrCreate, the chain entry built from it - while the next
        // invoice's entry still names the old hash. The chain forks, nothing
        // errors, and the stored document stops matching what the authority
        // holds. A double-clicked button is enough.
        //
        // The opt-in exists for two internal callers that re-enter only when
        // the hash is missing, which is finishing an issuance rather than
        // repeating one.
        if (! $reissue && $invoice->status !== InvoiceStatus::Draft) {
            throw $jurisdiction->refuseIssued($invoice);
        }

        $context = $jurisdiction->prepare($invoice, $organization, $reissue);

        // Counter, predecessor, document and chain entry are decided together,
        // inside one transaction holding the organization row.
        //
        // Reading the PIH outside this block is what let the chain fork: two
        // issuances for one tenant both read the head before either wrote, and
        // both claimed it. Allocating the counter here as well closes the
        // other way in — a document issued ahead of a lower-numbered draft,
        // which needs no concurrency at all to produce the same break.
        //
        // The lock is the organization row rather than the invoice rows, for
        // the reason generateNextIcv() gives: there are no invoice rows to
        // lock for a tenant's first document, so two requests would both find
        // nothing and both allocate 1. The organization row always exists.
        //
        // Signing happens under that lock, which is the cost. It serialises
        // issuance per tenant — correct, and worth measuring if a tenant ever
        // issues fast enough to feel it. An advisory lock keyed on org_id
        // would free the row without changing the guarantee.
        [$result, $issuedNow] = DB::transaction(function () use ($invoice, $organization, $jurisdiction, $context, $reissue): array {
            DB::table('organizations')
                ->where('id', $invoice->org_id)
                ->lockForUpdate()
                ->first();

            // Decided again here, on the row as it stands. The check above
            // read the caller's copy before the lock: two requests issuing one
            // draft both saw a draft, took the lock in turn, and the second -
            // still holding icv = null - allocated another counter and
            // re-signed a document already issued. Every issuance holds this
            // lock, so the row read under it is final.
            $invoice->refresh();

            if ($invoice->status !== InvoiceStatus::Draft) {
                if (! $reissue) {
                    throw $jurisdiction->refuseIssuedMeanwhile($invoice);
                }

                // Asked to finish an issuance another request has finished:
                // there is nothing left to do, and re-signing would be a
                // second document under the same counter.
                $finished = $jurisdiction->finished($invoice);

                if ($finished !== null) {
                    return [$finished, false];
                }
            }

            $jurisdiction->beforeNumbering($invoice, $context);

            // A draft left unnumbered takes its number now, under the same
            // lock as the counter: issued invoices run without gaps, and a
            // failure from here rolls the number back with the counter.
            if (blank($invoice->invoice_number)) {
                $invoice->invoice_number = $this->numbering->allocate($invoice);
            }

            // The moment of issue, set once: a reissue that finishes an
            // interrupted issuance keeps the time the document was given.
            if ($invoice->issued_at === null) {
                $invoice->issued_at = now();
            }

            $seal = $jurisdiction->seal($invoice, $organization, $context);

            $invoice->update([
                'invoice_number' => $invoice->invoice_number,
                'issued_at' => $invoice->issued_at,
                ...$seal->columns,
                'status' => InvoiceStatus::Issued,
            ]);

            return [$jurisdiction->afterIssued($invoice, $context, $seal), true];
        });

        $jurisdiction->afterCommit($invoice, $result, $issuedNow);

        return $result;
    }
}
