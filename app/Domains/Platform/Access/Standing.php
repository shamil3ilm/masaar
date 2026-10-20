<?php

declare(strict_types=1);

namespace App\Domains\Platform\Access;

/**
 * Where an organization's account with the platform stands, as Access
 * reads it: in good standing, or a stage of the overdue-payment policy -
 * reminded, in its grace, restricted, or frozen - by days past due or by a
 * platform admin's hand.
 *
 * Restricted is read-only: nothing new is issued and nobody new is added.
 * Frozen stops what else the account starts on its own besides (its
 * webhooks, offline issuing). Neither ever stops the organization reading
 * and exporting its own records, filing what it already issued, or
 * correcting an issued invoice with a credit or debit note - those deadlines
 * are the law's, not the platform's (FEATURES_KEPT).
 */
final readonly class Standing
{
    public const GOOD = 'good';

    public const REMINDERS = 'reminders';

    public const GRACE = 'grace';

    public const RESTRICTED = 'restricted';

    public const FROZEN = 'frozen';

    /** What a restricted account cannot start. */
    private const RESTRICTED_FEATURES = [
        Feature::IssueInvoices, Feature::IssueSimplified, Feature::IssueStandard,
        Feature::AddUsers, Feature::ErpPipeline, Feature::BatchSubmission,
    ];

    /** What a frozen account cannot start besides. */
    private const FROZEN_FEATURES = [Feature::Webhooks, Feature::OfflineMode];

    /**
     * What no standing ever takes away: reading, downloading and the API's
     * reads, filing and receiving, and correcting what was issued.
     */
    public const FEATURES_KEPT = [
        Feature::FileWithAuthority, Feature::ReceiveInvoices, Feature::PdfAndEmail, Feature::ApiAccess,
        Feature::CreditNotes, Feature::DebitNotes,
    ];

    /**
     * @param  int  $daysOverdue  how long its oldest unpaid invoice is past due; 0 for none
     */
    public function __construct(
        public string $stage,
        public int $daysOverdue = 0,
        public bool $manual = false,
        public ?string $reason = null,
    ) {}

    public static function good(): self
    {
        return new self(self::GOOD);
    }

    /** Whether this standing stops the feature. */
    public function blocks(Feature $feature): bool
    {
        if (in_array($feature, self::FEATURES_KEPT, true)) {
            return false;
        }

        return match ($this->stage) {
            self::RESTRICTED => in_array($feature, self::RESTRICTED_FEATURES, true),
            self::FROZEN => in_array($feature, [...self::RESTRICTED_FEATURES, ...self::FROZEN_FEATURES], true),
            default => false,
        };
    }

    /**
     * Whether it stops issuing a document of this kind: a new invoice, yes;
     * a credit or debit note correcting one already issued, never.
     *
     * @param  list<Feature>  $features  Feature::forDocument for the document
     */
    public function blocksDocument(array $features): bool
    {
        if (in_array(Feature::CreditNotes, $features, true) || in_array(Feature::DebitNotes, $features, true)) {
            return false;
        }

        foreach ($features as $feature) {
            if ($this->blocks($feature)) {
                return true;
            }
        }

        return false;
    }

    public function label(): string
    {
        return match ($this->stage) {
            self::REMINDERS => __('Payment overdue: reminders'),
            self::GRACE => __('Payment overdue: grace period'),
            self::RESTRICTED => __('Restricted: read-only'),
            self::FROZEN => __('Frozen'),
            default => __('In good standing'),
        };
    }
}
