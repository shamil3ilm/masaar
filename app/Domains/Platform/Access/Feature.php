<?php

declare(strict_types=1);

namespace App\Domains\Platform\Access;

use App\Domains\Platform\Services\KillSwitch;
use BackedEnum;

/**
 * The things a customer, or support on their behalf, asks whether they can
 * do: the catalogue Access answers for (docs/architecture/ACCESS.md).
 *
 * Each feature says which of the existing mechanisms govern it - the platform
 * kill switches every country obeys, the licence feature a plan grants, the
 * smallest portal role that may use it, whether the country must be switched
 * on and the organization cleared to deal with its authority. A country says
 * whether the feature exists there at all, through the engine's FeatureSupport
 * port, so nothing here names a country.
 */
enum Feature: string
{
    case IssueInvoices = 'issue_invoices';
    case IssueSimplified = 'issue_simplified';
    case IssueStandard = 'issue_standard';
    case CreditNotes = 'credit_notes';
    case DebitNotes = 'debit_notes';
    case FileWithAuthority = 'file_with_authority';
    case ReceiveInvoices = 'receive_invoices';
    case PdfAndEmail = 'pdf_and_email';
    case ApiAccess = 'api_access';
    case ErpPipeline = 'erp_pipeline';
    case BatchSubmission = 'batch_submission';
    case Webhooks = 'webhooks';
    case OfflineMode = 'offline_mode';

    public function label(): string
    {
        return match ($this) {
            self::IssueInvoices => __('Issue invoices'),
            self::IssueSimplified => __('Simplified (B2C) invoices'),
            self::IssueStandard => __('Standard (B2B) invoices'),
            self::CreditNotes => __('Credit notes'),
            self::DebitNotes => __('Debit notes'),
            self::FileWithAuthority => __('Filing and sending'),
            self::ReceiveInvoices => __('Receiving invoices'),
            self::PdfAndEmail => __('PDF and email'),
            self::ApiAccess => __('API access'),
            self::ErpPipeline => __('ERP pipeline'),
            self::BatchSubmission => __('Batch submission'),
            self::Webhooks => __('Webhooks'),
            self::OfflineMode => __('Offline mode'),
        };
    }

    /** Issuing of some kind: the irreversible step that numbers and seals a document. */
    public function issues(): bool
    {
        return in_array($this, [self::IssueInvoices, self::IssueSimplified, self::IssueStandard, self::CreditNotes, self::DebitNotes], true);
    }

    /**
     * The platform kill switches every country obeys for this feature. A
     * country adds its own (FeatureSupport::killSwitchesFor). offline_mode is
     * not here: it forces filing into the queue rather than stopping anything.
     *
     * @return list<string>
     */
    public function killSwitches(): array
    {
        return $this->issues() ? [KillSwitch::SWITCH_ISSUANCE] : [];
    }

    /**
     * New work with the authority: refused while the country is switched off
     * and while the organization is not cleared to file (FilingClearance).
     * Reading, downloading and the API's reads stay open either way.
     */
    public function startsFiling(): bool
    {
        return $this->issues() || $this === self::FileWithAuthority;
    }

    /** The licence feature a plan grants it by, when a plan restricts it at all. */
    public function licenceFeature(): ?string
    {
        return match ($this) {
            self::IssueSimplified => 'simplified_invoices',
            self::IssueStandard => 'standard_invoices',
            self::CreditNotes => 'credit_notes',
            self::DebitNotes => 'debit_notes',
            self::Webhooks => 'webhook_notifications',
            self::OfflineMode => 'offline_mode',
            default => null,
        };
    }

    /** Only reachable with a licence key: the partner API. */
    public function needsLicenceKey(): bool
    {
        return in_array($this, [self::ApiAccess, self::ErpPipeline, self::BatchSubmission], true);
    }

    /**
     * The smallest portal role that may use it: viewer, member or admin. Null
     * for what is not a person's action - the partner API carries no role.
     */
    public function minimumRole(): ?string
    {
        return match ($this) {
            self::IssueInvoices, self::IssueSimplified, self::IssueStandard,
            self::CreditNotes, self::DebitNotes, self::FileWithAuthority, self::PdfAndEmail => 'member',
            self::Webhooks => 'admin',
            self::ReceiveInvoices => 'viewer',
            self::ApiAccess, self::ErpPipeline, self::BatchSubmission, self::OfflineMode => null,
        };
    }

    /**
     * A platform admin may switch it off for one organization. Only where
     * every path that uses it honours the switch: issuing (each country's
     * issuing step), the ERP pipeline (PipelineService) and batch submission
     * (PipelineController::batch).
     *
     * Batch submission is the customer's unless switched off: no plan
     * restricts it while allowances are unlimited.
     */
    public function canSwitchOff(): bool
    {
        return $this->issues() || $this === self::ErpPipeline || $this === self::BatchSubmission;
    }

    /**
     * A platform admin may grant it beyond the plan. Only where the plan is
     * enforced through Access (the partner API's licence-feature check), and
     * never past a kill switch, the country, the organization's status or a
     * role.
     */
    public function canGrantBeyondPlan(): bool
    {
        return $this->licenceFeature() !== null && $this !== self::OfflineMode;
    }

    public function overridable(): bool
    {
        return $this->canSwitchOff() || $this->canGrantBeyondPlan();
    }

    /**
     * What a document needs, from its type and kind: issuing, then the
     * invoice type, then the note. The order is the order a refusal names.
     *
     * @return list<self>
     */
    public static function forDocument(BackedEnum|string|null $type, BackedEnum|string|null $documentType): array
    {
        $type = $type instanceof BackedEnum ? (string) $type->value : $type;
        $documentType = $documentType instanceof BackedEnum ? (string) $documentType->value : ($documentType ?? 'invoice');

        $features = [self::IssueInvoices];

        $features = match ($type) {
            'standard' => [...$features, self::IssueStandard],
            'simplified' => [...$features, self::IssueSimplified],
            default => $features,
        };

        return match ($documentType) {
            'credit_note' => [...$features, self::CreditNotes],
            'debit_note' => [...$features, self::DebitNotes],
            default => $features,
        };
    }

    /** The feature a licence feature grants, when it is one of the catalogue's. */
    public static function forLicenceFeature(string $name): ?self
    {
        foreach (self::cases() as $feature) {
            if ($feature->licenceFeature() === $name) {
                return $feature;
            }
        }

        return null;
    }
}
