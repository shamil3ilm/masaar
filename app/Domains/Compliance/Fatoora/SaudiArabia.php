<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora;

use App\Domains\Compliance\Engine\Contracts\IssueNotices;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Contracts\Transmission;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\Services\InvoiceIssuer;
use App\Domains\Compliance\Fatoora\Services\OfflineQueue;
use App\Domains\Compliance\Fatoora\Services\ZatcaJurisdiction;
use App\Domains\Invoice\Contracts\IssuingJurisdiction;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * Saudi Arabia's module: ZATCA's issuing step, and InvoiceIssuer to sign and
 * file. The default jurisdiction, for an organization with no country.
 *
 * One class answers every port because each is a line or two of adapting
 * what Fatoora already does. A port that grows gets a class of its own.
 */
class SaudiArabia implements IssueNotices, Jurisdiction, Transmission
{
    public function __construct(
        private readonly InvoiceIssuer $issuer,
        private readonly OfflineQueue $offline,
        private readonly ZatcaJurisdiction $step,
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

    /** Always: the platform was built for Saudi Arabia and has no switch for it. */
    public function enabled(): bool
    {
        return true;
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
}
