<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\IssuingStep;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\DeviceStatus;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\Presentation;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\Sectors;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\SetupSteps;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\Website;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\Dashboard;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\TaxReports;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\InvoiceRules;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\IssueDeadline;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\LanguageRules;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\SellerRules;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\VatTreatments;
use App\Domains\Compliance\Engine\Contracts\Dashboard as DashboardPort;
use App\Domains\Compliance\Engine\Contracts\Devices;
use App\Domains\Compliance\Engine\Contracts\FeatureSupport;
use App\Domains\Compliance\Engine\Contracts\InvoiceRules as InvoiceRulesPort;
use App\Domains\Compliance\Engine\Contracts\IssueNotices;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Contracts\LanguageRules as LanguageRulesPort;
use App\Domains\Compliance\Engine\Contracts\Presentation as PresentationPort;
use App\Domains\Compliance\Engine\Contracts\Sectors as SectorsPort;
use App\Domains\Compliance\Engine\Contracts\SellerRules as SellerRulesPort;
use App\Domains\Compliance\Engine\Contracts\SetupSteps as SetupStepsPort;
use App\Domains\Compliance\Engine\Contracts\TaxReports as TaxReportsPort;
use App\Domains\Compliance\Engine\Contracts\Transmission;
use App\Domains\Compliance\Engine\Contracts\VatTreatments as VatTreatmentsPort;
use App\Domains\Compliance\Engine\Contracts\Website as WebsitePort;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Networks\Peppol\NetworkServiceProvider;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Feature;

/**
 * The United Arab Emirates' module: a Peppol PINT AE document, issued by
 * InvoiceIssuer and sent through the accredited service provider over the
 * shared Peppol network.
 *
 * One class answers the ports that are a line or two of adapting what
 * Peppol already does. The rules ports are classes of their own, in Rules/;
 * the presentation and setup ports in Presentation/; the tax report and the
 * dashboard in Reports/.
 */
class Module implements FeatureSupport, IssueNotices, Jurisdiction, Transmission
{
    public function __construct(
        private readonly InvoiceIssuer $issuer,
        private readonly IssuingStep $step,
        private readonly JurisdictionAvailability $availability,
        private readonly SellerRules $seller,
        private readonly InvoiceRules $invoices,
        private readonly VatTreatments $vat,
        private readonly LanguageRules $language,
        private readonly Presentation $presentation,
        private readonly TaxReports $reports,
        private readonly Dashboard $dashboard,
        private readonly SetupSteps $setup,
        private readonly Sectors $sectors,
        private readonly DeviceStatus $devices,
        private readonly Website $website,
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

    /**
     * The simulator says so in words no one would take for a provider: it is
     * never ready for production, which refuses to boot with it
     * (NetworkServiceProvider::refuseSimulatorIn).
     */
    public function readiness(): array
    {
        return ['Service provider' => match (true) {
            config('peppol.gateway') === NetworkServiceProvider::SIMULATOR => 'Simulator, not for production: documents are checked against the PINT AE rules and answered here, and nothing reaches the network',
            filled(config('peppol.asp.base_url')) => 'Connected',
            default => 'None: documents wait to be uploaded by hand',
        }];
    }

    /** A provider's missing credentials are refused at boot (AppServiceProvider); nothing more. */
    public function readinessProblems(): array
    {
        return [];
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

    public function sellerRules(): SellerRulesPort
    {
        return $this->seller;
    }

    public function invoiceRules(): InvoiceRulesPort
    {
        return $this->invoices;
    }

    public function vatTreatments(): VatTreatmentsPort
    {
        return $this->vat;
    }

    public function languageRules(): LanguageRulesPort
    {
        return $this->language;
    }

    public function presentation(): PresentationPort
    {
        return $this->presentation;
    }

    public function reports(): TaxReportsPort
    {
        return $this->reports;
    }

    public function dashboard(): DashboardPort
    {
        return $this->dashboard;
    }

    public function setup(): SetupStepsPort
    {
        return $this->setup;
    }

    public function sectors(): SectorsPort
    {
        return $this->sectors;
    }

    public function devices(): Devices
    {
        return $this->devices;
    }

    public function website(): WebsitePort
    {
        return $this->website;
    }

    /**
     * PINT AE is business to business: no simplified invoice and no debit
     * note (a correction is a credit note). The e-invoice is the tax
     * invoice, and a buyer not yet on electronic invoicing is given a regular
     * one, "e.g. in pdf", beside it (Electronic Invoicing Guidelines v1.1,
     * 10.2.2): so a PDF to print or email. The service provider carries every
     * document, so nothing waits offline. Receiving is the network's inbound
     * documents.
     */
    public function supportsFeature(Feature $feature): bool
    {
        return ! in_array($feature, [Feature::IssueSimplified, Feature::DebitNotes, Feature::OfflineMode], true);
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

    /** The provider passes a document or refuses it; it advises nothing beside. */
    public function advisories(Invoice $invoice): array
    {
        return [];
    }

    /** A refused UAE e-invoice keeps the provider's reasons on its Peppol document. */
    public function rejections(Invoice $invoice): array
    {
        return [];
    }
}
