<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Contracts;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Throwable;

/**
 * One country's part of issuing an invoice.
 *
 * IssuingLifecycle owns what every country shares - the already-issued guard,
 * the organization-row lock, the number, the moment of issue, status Issued
 * and the transaction - and calls these at fixed points:
 *
 *   refuseIssued()           before anything, for a non-draft not being finished
 *   prepare()                before the lock; throws to refuse, nothing written
 *   -- transaction, organization row locked, invoice refreshed --
 *   refuseIssuedMeanwhile()  the row is no longer a draft and this is no reissue
 *   finished()               the row is no longer a draft and this is a reissue
 *   beforeNumbering()        a draft (or unfinished reissue), before its number
 *   -- number allocated if blank, issued_at set if null --
 *   seal()                   the document; its columns are saved with the status
 *   -- invoice updated: number, issued_at, seal columns, status Issued --
 *   afterIssued()            bookkeeping on the issued row; returns the result
 *   -- commit --
 *   afterCommit()            work that must not run inside the transaction
 *
 * $context is whatever prepare() returned, handed back to each later step.
 */
interface IssuingJurisdiction
{
    /** The refusal for an invoice that is not a draft, raised before anything else. */
    public function refuseIssued(Invoice $invoice): Throwable;

    /** The refusal for a draft another request issued while this one waited on the lock. */
    public function refuseIssuedMeanwhile(Invoice $invoice): Throwable;

    /**
     * Checks and lookups before the lock. Throw to refuse: the invoice is
     * still a draft without a number.
     *
     * @return mixed the context handed to every later step
     */
    public function prepare(Invoice $invoice, Organization $organization, bool $reissue): mixed;

    /**
     * Asked to finish an issuance the row shows is no longer a draft: what
     * the finished issuance produced, or null when it still needs finishing
     * and the steps below should run.
     */
    public function finished(Invoice $invoice): mixed;

    /** Under the lock, before the invoice is numbered. */
    public function beforeNumbering(Invoice $invoice, mixed $context): void;

    /** Under the lock, once the number and moment of issue are set. */
    public function seal(Invoice $invoice, Organization $organization, mixed $context): Seal;

    /**
     * Under the lock, after the invoice is saved as Issued.
     *
     * @return mixed what the issuance returns to its caller
     */
    public function afterIssued(Invoice $invoice, mixed $context, Seal $seal): mixed;

    /**
     * After the lifecycle's transaction returns, with what it produced.
     * $issuedNow is false when finished() answered instead.
     */
    public function afterCommit(Invoice $invoice, mixed $result, bool $issuedNow): void;
}
