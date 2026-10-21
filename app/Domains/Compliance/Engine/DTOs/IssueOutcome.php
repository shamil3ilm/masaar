<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * What one issuance did, in any jurisdiction.
 *
 * Carries the raw submission array rather than a tidied subset. A country's
 * filing answers in shapes of its own - Saudi filing alone has six: the online
 * record, the offline queue entry, an async queue entry, a submission error
 * and two idempotency replies - and each holds keys the others do not:
 * queue_id, job_id, check_status_url, can_retry, retry_after, cached. A DTO
 * that picked a few would silently drop the rest, and PipelineService has to
 * rebuild its zatca_response block from exactly four of them. A module whose
 * transport answers differently maps its answer into those keys.
 *
 * Under Engine/DTOs/ because every jurisdiction module returns it through the
 * Transmission port, so it belongs to the engine rather than to one country.
 */
final readonly class IssueOutcome
{
    /**
     * @param  list<string>  $errors  Stage-prefixed, as PipelineService worded them.
     * @param  list<string>  $warnings
     * @param  array<string, mixed>|null  $submission  Raw, as the jurisdiction's filing returned it.
     */
    private function __construct(
        public bool $issued,
        public bool $attemptedFiling,
        public array $errors,
        public array $warnings,
        public ?array $submission,
    ) {}

    /**
     * Issuing did not happen. The invoice is unchanged.
     *
     * There is deliberately no separate "refused" state. A refused issuance -
     * a re-issue, or a country's rules - arrives here as a failure like any
     * other, and the caller's question is the same either way: was it issued?
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
     * Whether filing was handed to a queue - an offline queue, or an
     * asynchronous job - rather than answered: the document is not filed yet.
     */
    public function queued(): bool
    {
        return in_array($this->submission['status'] ?? null, ['queued', 'queued_offline'], true);
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
