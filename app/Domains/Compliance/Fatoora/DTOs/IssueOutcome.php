<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\DTOs;

/**
 * What one issuance did.
 *
 * Carries the raw submission array rather than a tidied subset. OfflineFallback
 * answers in six different shapes - the online record, the offline queue entry,
 * an async queue entry, a submission error, and two idempotency replies - and
 * each holds keys the others do not: queue_id, job_id, check_status_url,
 * can_retry, retry_after, cached. A DTO that picked a few would silently drop
 * the rest, and PipelineService has to rebuild its zatca_response block from
 * exactly four of them.
 *
 * Under Fatoora/DTOs/ because a DTO belongs to the domain that produces it,
 * not the one it describes: InvoiceIssuer returns this, so Compliance\Fatoora
 * owns it even though what it reports on is an invoice. That is where the
 * other seven DTOs in this domain sit.
 */
final readonly class IssueOutcome
{
    /**
     * @param  list<string>  $errors  Stage-prefixed, as PipelineService worded them.
     * @param  list<string>  $warnings
     * @param  array<string, mixed>|null  $submission  Raw, as OfflineFallback returned it.
     */
    private function __construct(
        public bool $issued,
        public bool $attemptedFiling,
        public array $errors,
        public array $warnings,
        public ?array $submission,
    ) {}

    /**
     * Signing did not happen. The invoice is unchanged.
     *
     * There is deliberately no separate "refused" state. Re-issuing will be
     * refused by Submitter::generate() itself in a later task, which throws,
     * so it arrives here as a generation failure like any other - and the
     * caller's question is the same either way: was it issued?
     */
    public static function notIssued(string $error): self
    {
        return new self(false, false, [$error], [], null);
    }

    /** Issued, and filing was deliberately not attempted. */
    public static function issuedOnly(): self
    {
        return new self(true, false, [], [], null);
    }

    /**
     * Issued, and the authority answered - accepting, rejecting or queueing
     * the document. Its reply is carried through untouched.
     *
     * @param  array<string, mixed>  $submission
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public static function filed(array $submission, array $errors, array $warnings): self
    {
        return new self(true, true, $errors, $warnings, $submission);
    }

    /**
     * Issued, filing attempted, and nothing came back.
     *
     * Still issued: the invoice holds its counter and its place in the chain,
     * and withdrawing it would leave a gap, which is the one thing the chain
     * cannot have.
     *
     * A null submission is how a caller tells this apart from an authority
     * that answered with nothing - PipelineService reports no zatca_response
     * at all here, rather than a block of null fields.
     */
    public static function filingFailed(string $error): self
    {
        return new self(true, true, [$error], [], null);
    }
}
