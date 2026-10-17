<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Services;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Compliance\Engine\Exceptions\JurisdictionUnavailableException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * The one way shared code issues an invoice.
 *
 * It resolves the organization's jurisdiction and hands the call to that
 * module's Transmission port. The caller never learns which country it was,
 * and has nothing to branch on.
 *
 * For the length of each call every log line carries the jurisdiction, the
 * step and the identifiers, so a line written deep inside a country's filing
 * can be traced back to the call that caused it. Context::scope() puts the
 * previous context back when the call returns or throws, and a job queued
 * during the call carries the same values to its worker.
 */
class ComplianceEngine
{
    public function __construct(private readonly JurisdictionResolver $resolver) {}

    /**
     * The organization's module, for a caller that needs its identity or a
     * port other than issuing.
     *
     * @throws JurisdictionUnavailableException
     */
    public function jurisdictionFor(Organization $organization): Jurisdiction
    {
        return $this->resolver->forOrganization($organization);
    }

    /**
     * Issue a draft and, when asked, file or send it.
     *
     * @throws JurisdictionUnavailableException before anything is written
     */
    public function issue(Invoice $invoice, Organization $organization, IssueOptions $options): IssueOutcome
    {
        $jurisdiction = $this->resolver->forOrganization($organization);

        return $this->traced($jurisdiction, 'issue', $invoice, $organization,
            fn (): IssueOutcome => $jurisdiction->transmission()->issue($invoice, $organization, $options));
    }

    /**
     * Carry on with an invoice already issued: file or send it if that is
     * still owed. Null when there is nothing to do now.
     *
     * @throws JurisdictionUnavailableException before anything is written
     */
    public function resume(Invoice $invoice, Organization $organization, IssueOptions $options): ?IssueOutcome
    {
        $jurisdiction = $this->resolver->forOrganization($organization);

        return $this->traced($jurisdiction, 'resume', $invoice, $organization,
            fn (): ?IssueOutcome => $jurisdiction->transmission()->resume($invoice, $organization, $options));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function traced(Jurisdiction $jurisdiction, string $step, Invoice $invoice, Organization $organization, Closure $call): mixed
    {
        return Context::scope($call, [
            'jurisdiction' => $jurisdiction->code(),
            'step' => $step,
            'org_id' => $organization->id,
            'invoice_id' => $invoice->id,
        ]);
    }
}
