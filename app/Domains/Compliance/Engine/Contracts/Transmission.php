<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * A country's issue-and-transmit: the invoice is issued through
 * IssuingLifecycle with the country's issuing step, then filed or sent.
 *
 * Neither method throws for the invoice's own problems. A refusal answers
 * IssueOutcome::notIssued() with the invoice still a draft; a filing that
 * failed answers with the invoice issued and the error beside it, because an
 * issued invoice stays issued.
 */
interface Transmission
{
    /** Issue a draft, then file or send it when $options->file. */
    public function issue(Invoice $invoice, Organization $organization, IssueOptions $options): IssueOutcome;

    /**
     * Asked again about an invoice already issued - a retry - file or send
     * it if that is still owed and asked for.
     *
     * @return IssueOutcome|null null when there is nothing to do now, and the
     *                           caller answers from what is stored
     */
    public function resume(Invoice $invoice, Organization $organization, IssueOptions $options): ?IssueOutcome;

    /** Whether the organization can file now, for a caller that files only then. */
    public function readyToFile(Organization $organization): bool;

    /**
     * Whether this deployment hands the country's documents to the live
     * authority or network, rather than its sandbox or a simulator. Decided
     * by the deployment's configuration, or for a module that keeps an
     * environment per organization by the organization's; anything a module
     * cannot call a sandbox is live. A sandbox API key never issues or files
     * where this is true (SandboxGuard).
     */
    public function live(?Organization $organization = null): bool;
}
