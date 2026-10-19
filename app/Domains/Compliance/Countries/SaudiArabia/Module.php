<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\IssuingStep;
use App\Domains\Compliance\Countries\SaudiArabia\Presentation\DeviceStatus;
use App\Domains\Compliance\Countries\SaudiArabia\Presentation\Presentation;
use App\Domains\Compliance\Countries\SaudiArabia\Presentation\Sectors;
use App\Domains\Compliance\Countries\SaudiArabia\Presentation\SetupSteps;
use App\Domains\Compliance\Countries\SaudiArabia\Presentation\Website;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\Dashboard;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\TaxReports;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\InvoiceRules;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\LanguageRules;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\RecurringSupply;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\SellerRules;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\VatTreatments;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationReadiness;
use App\Domains\Compliance\Engine\Contracts\Dashboard as DashboardPort;
use App\Domains\Compliance\Engine\Contracts\Devices;
use App\Domains\Compliance\Engine\Contracts\FeatureSupport;
use App\Domains\Compliance\Engine\Contracts\InvoiceRules as InvoiceRulesPort;
use App\Domains\Compliance\Engine\Contracts\IssueNotices;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Contracts\LanguageRules as LanguageRulesPort;
use App\Domains\Compliance\Engine\Contracts\Presentation as PresentationPort;
use App\Domains\Compliance\Engine\Contracts\RecurringSupply as RecurringSupplyPort;
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
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Services\KillSwitch;

/**
 * Saudi Arabia's module: ZATCA's issuing step, and InvoiceIssuer to sign and
 * file. The default jurisdiction, for an organization with no country.
 *
 * One class answers the ports that are a line or two of adapting what
 * Fatoora already does. The rules ports are classes of their own, in Rules/;
 * the presentation and setup ports in Presentation/; the tax report and the
 * dashboard in Reports/.
 */
class Module implements FeatureSupport, IssueNotices, Jurisdiction, Transmission
{
    public function __construct(
        private readonly InvoiceIssuer $issuer,
        private readonly OfflineQueue $offline,
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
        private readonly RevocationReadiness $revocationReadiness,
        private readonly Website $website,
        private readonly RecurringSupply $recurring,
    ) {}

    public function code(): string
    {
        return 'SA';
    }

    public function name(): string
    {
        return 'Saudi Arabia';
    }

    public function timezone(): string
    {
        return 'Asia/Riyadh';
    }

    public function currency(): string
    {
        return FatooraConfig::CURRENCY_SAR;
    }

    public function filingSystem(): string
    {
        return 'ZATCA (Fatoora)';
    }

    /** The admin console's switch, else ZATCA_ENABLED: on unless a deployment turns it off. */
    public function enabled(): bool
    {
        return $this->availability->enabled($this->code(), (bool) config('fatoora.enabled'));
    }

    public function readiness(): array
    {
        // What the revocation check before every stamp has to work with
        // (Security Features Implementation Standard v1.2, section 2.2.1,
        // requirement 14): without the CA chain an http list cannot be
        // verified, and a certificate naming no list needs one configured.
        return [
            'ZATCA environment' => (string) config('fatoora.environment'),
            'CA certificate chain' => filled(config('fatoora.certificate.chain_path')) ? 'configured' : 'not configured',
            'Revocation lists' => config('fatoora.certificate.crl_urls') !== []
                ? implode(', ', (array) config('fatoora.certificate.crl_urls'))
                : 'only those certificates name',
        ];
    }

    /** No revocation source for a certificate in use, or no CA chain (RevocationReadiness). */
    public function readinessProblems(): array
    {
        return $this->revocationReadiness->problems();
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

    public function recurringSupply(): RecurringSupplyPort
    {
        return $this->recurring;
    }

    public function website(): WebsitePort
    {
        return $this->website;
    }

    /**
     * Everything but receiving: ZATCA clears and reports a seller's invoices
     * and hands nothing on to the buyer, so there is nothing to receive.
     */
    public function supportsFeature(Feature $feature): bool
    {
        return $feature !== Feature::ReceiveInvoices;
    }

    /**
     * Issuing signs, so the signing switch stops it (IssuingStep::prepare);
     * filing stops at the submission switch (SubmissionGuard), which also
     * checks clearance or reporting by the invoice's type.
     */
    public function killSwitchesFor(Feature $feature): array
    {
        return match (true) {
            $feature->issues() => [KillSwitch::SWITCH_SIGNING],
            $feature === Feature::FileWithAuthority => [KillSwitch::SWITCH_SUBMISSION],
            default => [],
        };
    }

    public function issue(Invoice $invoice, Organization $organization, IssueOptions $options): IssueOutcome
    {
        return $this->issuer->issue(
            $invoice,
            $organization,
            file: $options->file,
            idempotencyKey: $options->idempotencyKey,
        );
    }

    /**
     * Issued and not filed - the filing failed, or was refused before it
     * began - is filed now, unless it is waiting in the offline queue, where
     * sending it again would file it twice.
     */
    public function resume(Invoice $invoice, Organization $organization, IssueOptions $options): ?IssueOutcome
    {
        if (! $options->file || $this->offline->isQueued($invoice)) {
            return null;
        }

        return $this->issuer->file($invoice, $options->idempotencyKey);
    }

    /**
     * Onboarded, so a certificate exists. Without one SubmissionGuard throws
     * CERT_NOT_FOUND, which is not in OfflineFallback's connectivity list, so
     * nothing would queue.
     */
    public function readyToFile(Organization $organization): bool
    {
        return (bool) $organization->zatca_onboarded;
    }

    public function filed(): string
    {
        return __('Issued and filed with ZATCA.');
    }

    public function notFiled(): string
    {
        return __('Issued. It has not been filed with ZATCA: this organization has no certificate yet, so complete onboarding before the reporting deadline.');
    }

    /**
     * The warnings ZATCA accepted it with, as the verdict kept them on the
     * invoice (InvoiceVerdict) - BR-KSA-F-13 doubting an Other Seller or
     * Buyer ID, for one.
     */
    public function advisories(Invoice $invoice): array
    {
        return FatooraResponse::messages((array) ($invoice->zatca_response['warnings'] ?? []));
    }

    /**
     * The rules ZATCA rejected it for, as the verdict kept them from
     * validationResults.errorMessages (InvoiceVerdict).
     */
    public function rejections(Invoice $invoice): array
    {
        return $invoice->status === InvoiceStatus::Rejected
            ? FatooraResponse::details((array) ($invoice->zatca_response['errors'] ?? []))
            : [];
    }
}
