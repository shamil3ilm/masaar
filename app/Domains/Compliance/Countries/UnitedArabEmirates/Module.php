<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\IssuingStep;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\IssueDeadline;
use App\Domains\Compliance\Engine\Contracts\FeatureSupport;
use App\Domains\Compliance\Engine\Contracts\IssueNotices;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Contracts\Transmission;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Feature;

/**
 * The United Arab Emirates' module: a Peppol PINT AE document, issued by
 * InvoiceIssuer and sent through the accredited service provider over the
 * shared Peppol network.
 *
 * One class answers every port because each is a line or two of adapting
 * what Peppol already does. A port that grows gets a class of its own.
 */
class Module implements FeatureSupport, IssueNotices, Jurisdiction, Transmission
{
    public function __construct(
        private readonly InvoiceIssuer $issuer,
        private readonly IssuingStep $step,
        private readonly JurisdictionAvailability $availability,
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

    public function filingSystem(): string
    {
        return 'Peppol PINT AE';
    }

    /** The admin console's switch, else PEPPOL_AE_ENABLED: off unless a deployment turns it on. */
    public function enabled(): bool
    {
        return $this->availability->enabled($this->code(), (bool) config('peppol.countries.AE.enabled'));
    }

    public function readiness(): array
    {
        return ['Service provider' => filled(config('peppol.asp.base_url'))
            ? 'Connected'
            : 'None: documents wait to be uploaded by hand'];
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

    public function features(): FeatureSupport
    {
        return $this;
    }

    /**
     * PINT AE is business to business: no simplified invoice and no debit
     * note (a correction is a credit note). The document is the e-invoice, so
     * there is no PDF to share or email (InvoicePdf needs a seal it does not
     * have), and the service provider carries every document, so nothing
     * waits offline. Receiving is the network's inbound documents.
     */
    public function supportsFeature(Feature $feature): bool
    {
        return ! in_array($feature, [Feature::IssueSimplified, Feature::DebitNotes, Feature::PdfAndEmail, Feature::OfflineMode], true);
    }

    /** None beyond the shared issuance switch: sending is the network's, which no switch stops. */
    public function killSwitchesFor(Feature $feature): array
    {
        return [];
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
