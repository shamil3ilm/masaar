<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol;

use App\Domains\Compliance\Engine\Contracts\IssueNotices;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Contracts\Transmission;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Peppol\Services\IssueDeadline;
use App\Domains\Compliance\Peppol\Services\UaeInvoiceIssuer;
use App\Domains\Compliance\Peppol\Services\UaeJurisdiction;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * The United Arab Emirates' module: a Peppol PINT AE document, issued by
 * UaeInvoiceIssuer and sent through the accredited service provider. Never
 * signed for ZATCA.
 *
 * One class answers every port because each is a line or two of adapting
 * what Peppol already does. A port that grows gets a class of its own.
 */
class UnitedArabEmirates implements IssueNotices, Jurisdiction, Transmission
{
    public function __construct(
        private readonly UaeInvoiceIssuer $issuer,
        private readonly UaeJurisdiction $step,
    ) {}

    public function code(): string
    {
        return 'AE';
    }

    public function name(): string
    {
        return 'United Arab Emirates';
    }

    public function timezone(): string
    {
        return 'Asia/Dubai';
    }

    public function currency(): string
    {
        return 'AED';
    }

    /** PEPPOL_AE_ENABLED: off unless the deployment files in the UAE. */
    public function enabled(): bool
    {
        return (bool) config('peppol.countries.AE.enabled');
    }

    public function issuingStep(): IssuingJurisdiction
    {
        return $this->step;
    }

    public function transmission(): Transmission
    {
        return $this;
    }

    public function notices(): IssueNotices
    {
        return $this;
    }

    /**
     * $options->file false issues it and prepares its document ('ready') but
     * does not queue it; POST /api/compliance/ae/invoices/{invoice}/send, or
     * a call with file true, queues it once. The document stands in for the
     * submission, and a late issue is a warning.
     */
    public function issue(Invoice $invoice, Organization $organization, IssueOptions $options): IssueOutcome
    {
        try {
            $document = $this->issuer->issue($invoice, $options->file);
        } catch (IssuanceRefusedException $e) {
            return IssueOutcome::notIssued($e->getMessage());
        }

        return IssueOutcome::filed(
            ['submission_id' => $document->id, 'state' => $document->status],
            [],
            IssueDeadline::warnings($document),
        );
    }

    /**
     * Issuing an issued invoice answers with its document, and queues it if
     * asked and not queued before - so a retry is the same call.
     */
    public function resume(Invoice $invoice, Organization $organization, IssueOptions $options): ?IssueOutcome
    {
        return $this->issue($invoice, $organization, $options);
    }

    /** Always: there is nothing to onboard, the service provider carries every document. */
    public function readyToFile(Organization $organization): bool
    {
        return true;
    }

    public function filed(): string
    {
        return __('Issued, and its UAE e-invoice is queued for sending.');
    }

    public function notFiled(): string
    {
        return __('Issued. Its UAE e-invoice is ready and has not been queued for sending.');
    }
}
