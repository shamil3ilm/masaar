<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * A new customer's free time: Finance › Settings' free months (one at
 * launch), or the customer's own, counted from its approval and kept on
 * the organization then (free_until).
 *
 * Approving a company without an end date gives its plan this long
 * (OrganizationReview), and nothing is invoiced for it: the first invoice
 * is for the time after it (Receivables). No free months means no free
 * time, and an approval without an end date runs until it is invoiced.
 */
class FreePeriod
{
    public function __construct(
        private readonly FinanceSettings $settings,
    ) {}

    /** When the free time ends for a company approved at that moment, or null for none. */
    public function endsFrom(CarbonImmutable $approvedAt, string $organizationId): ?CarbonImmutable
    {
        $months = $this->settings->freeMonths($organizationId);

        return $months > 0 ? $approvedAt->addMonthsNoOverflow($months) : null;
    }

    /**
     * When a customer's free time ends, or null when it has none: the end
     * fixed when it was approved, so a later change to the free months
     * moves only the free time of companies approved after it.
     */
    public function endsFor(Organization $customer): ?CarbonImmutable
    {
        // Not approved yet, or turned down: there is no approval to count from.
        if ($customer->reviewed_at === null || in_array($customer->status, [Organization::STATUS_PENDING_REVIEW, Organization::STATUS_REJECTED], true)) {
            return null;
        }

        return $customer->free_until;
    }
}
