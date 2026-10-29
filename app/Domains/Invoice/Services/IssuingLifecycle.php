<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Services\SandboxGuard;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Issuing as every country has it: under its chain's lock a draft takes its
 * number and its moment of issue and becomes Issued. What a country
 * adds - checks, a counter, a signature, a document of its own - arrives
 * through the IssuingJurisdiction it is given, at the points that contract
 * lists.
 */
class IssuingLifecycle
{
    /** Tries of the issuing transaction when the database deadlocks it. */
    private const ATTEMPTS = 3;

    /** What a lock waited out reads as, on MySQL and on SQLite. */
    private const LOCK_WAITS = ['Lock wait timeout exceeded', 'database is locked', 'database table is locked'];

    public function __construct(
        private readonly InvoiceNumbering $numbering,
        private readonly AuditService $audit,
        private readonly SandboxGuard $sandbox,
        private readonly ConcurrencyErrorDetector $concurrency,
    ) {}

    /**
     * @param  bool  $reissue  Allow a document that is no longer a draft to be
     *                         issued again. Only for a caller completing an
     *                         issuance that did not finish.
     * @return mixed what the jurisdiction's afterIssued() or finished() returned
     */
    public function issue(Invoice $invoice, Organization $organization, IssuingJurisdiction $jurisdiction, bool $reissue = false): mixed
    {
        // An organization's sandbox API key seals nothing into its real
        // chain where its country files live, whichever path it came by.
        $this->sandbox->assertMayFile($organization);

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
        $scope = $jurisdiction->lockScope($invoice, $context);

        // Counter, predecessor, document and chain entry are decided together,
        // inside one transaction holding the chain's lock.
        //
        // Reading the PIH outside this block is what let the chain fork: two
        // issuances for one chain both read the head before either wrote, and
        // both claimed it. Allocating the counter here as well closes the
        // other way in - a document issued ahead of a lower-numbered draft,
        // which needs no concurrency at all to produce the same break.
        //
        // The lock is a row of its own (issuing_locks), one per chain, rather
        // than the invoice rows: there are none to lock for a chain's first
        // document, so two requests would both find nothing and both
        // allocate 1. The upsert creates the row or waits for whoever holds
        // it, in one statement.
        //
        // It was the organization row, which serialised every till of a
        // tenant behind every other although each device keeps its own chain
        // - and an exclusive lock on that row also held up every insert whose
        // foreign key checks it. The jurisdiction names the chain, so a
        // country with a chain per device lets its devices issue at once.
        // Signing still happens under the lock: one chain issues one document
        // at a time, which is what a chain is.
        $issue = function () use ($invoice, $organization, $jurisdiction, $context, $reissue, $scope): array {
            $this->holdChain($invoice, $scope);

            // Decided again here, on the row as it stands. The check above
            // read the caller's copy before the lock: two requests issuing one
            // draft both saw a draft, took the lock in turn, and the second -
            // still holding icv = null - allocated another counter and
            // re-signed a document already issued.
            //
            // The row itself is locked as well. Two requests for one draft
            // normally name the same chain and wait on it, but a device
            // onboarded between their prepare() calls would give them
            // different scopes, and only the row is common to both.
            Invoice::acrossTenants()->whereKey($invoice->getKey())->lockForUpdate()->first();
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

            // And by whom: whoever is signed in, or nobody for an
            // integration or a queue. Only while it is still a draft - an
            // issued document's columns are frozen.
            if ($invoice->status === InvoiceStatus::Draft && $invoice->issued_by === null) {
                $invoice->issued_by = auth()->id();
            }

            $seal = $jurisdiction->seal($invoice, $organization, $context);

            $invoice->update([
                'invoice_number' => $invoice->invoice_number,
                'issued_at' => $invoice->issued_at,
                'issued_by' => $invoice->issued_by,
                ...$seal->columns,
                'status' => InvoiceStatus::Issued,
            ]);

            $after = $jurisdiction->afterIssued($invoice, $context, $seal);

            // On the organization's activity record: who issued what. Once,
            // for the issuance that made it; a reissue finishing one adds
            // nothing. In the same transaction as the issuance, so a document
            // is never issued without its entry, nor an entry left for a
            // document that rolled back - it was written after the commit, and
            // a failure between the two issued a document off the record.
            $this->audit->logFor((string) $invoice->org_id, 'invoice.issued', $invoice, [
                'invoice_number' => $invoice->invoice_number,
                'document_type' => $invoice->document_type instanceof DocumentType ? $invoice->document_type->value : $invoice->document_type,
                'branch_id' => $invoice->branch_id,
            ]);

            return [$after, true];
        };

        // A deadlock is the database choosing this transaction to undo so
        // another can finish: nothing of it was kept, and it is tried again,
        // a bounded number of times - each from the row as it then stands,
        // since the closure refreshes the invoice under the lock. Only as a
        // transaction of its own: inside a caller's, the database undid the
        // caller's work too, and that is the caller's to retry.
        try {
            [$result, $issuedNow] = DB::transaction(fn (): array => $this->waitingOnce($issue), self::ATTEMPTS);
        } catch (Throwable $e) {
            if (! $this->concurrency->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw self::busy($e);
        }

        $jurisdiction->afterCommit($invoice, $result, $issuedNow);

        return $result;
    }

    /**
     * The issuance, or the device is busy.
     *
     * The chain's lock is held while one document is signed, so on a busy
     * device a request waits its turn - for as long as the database waits
     * for a lock, on the chain or on a shared number series. Waited out, it
     * is not tried again: it would wait as long a second time. The till is
     * told to send it again instead, and nothing is issued meanwhile: the
     * transaction is undone, the draft stays a draft, and the counter, the
     * chain and the number sequence are where they were.
     *
     * @param  Closure(): array{0: mixed, 1: bool}  $issue
     * @return array{0: mixed, 1: bool}
     */
    private function waitingOnce(Closure $issue): array
    {
        try {
            return $issue();
        } catch (Throwable $e) {
            if (! Str::contains($e->getMessage(), self::LOCK_WAITS)) {
                throw $e;
            }

            throw self::busy($e);
        }
    }

    /** Take the chain's lock: created, or waited for, in one statement. */
    private function holdChain(Invoice $invoice, string $scope): void
    {
        DB::table('issuing_locks')->upsert(
            [['org_id' => $invoice->org_id, 'scope' => $scope, 'held_at' => now()]],
            ['org_id', 'scope'],
            ['held_at'],
        );
    }

    /** Retryable, as the API answers it: 503 with Retry-After (ErrorCode::SYS_BUSY). */
    private static function busy(Throwable $cause): ComplianceException
    {
        return new ComplianceException(
            'Another invoice is being issued on this device. Nothing was issued; send the same request again.',
            ErrorCode::SYS_BUSY,
            [],
            $cause,
        );
    }
}
