<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Models\AccountHold;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\AccountStanding;
use App\Domains\Platform\Access\Standing;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

/**
 * The overdue-payment freeze: where a customer's account stands, by its
 * oldest unpaid invoice and the policy's stages, or by a platform admin's
 * hand.
 *
 * Built and shipped off (Finance › Settings: freeze accounts with overdue
 * payments). On, a customer is reminded for the policy's reminder days past
 * the due date, then given its grace days, then restricted to reading -
 * nothing new issued, nobody new added - for its restricted days, and then
 * frozen. A customer can have its own days, or be exempt.
 *
 * A platform admin can freeze an account by hand, or lift the freeze until a
 * date, with a reason, under the accounts.freeze and accounts.unfreeze
 * approval policies; a hand applies whether the policy is on or not, and the
 * latest decides.
 *
 * Access reads it as one decision, "Account frozen: payment overdue", and no
 * stage ever stops a customer reading and exporting its own records, filing
 * what it already issued, or correcting an issued invoice with a note
 * (Standing::FEATURES_KEPT).
 */
class Freeze implements AccountStanding
{
    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly AuditService $audit,
    ) {}

    public function of(Organization $organization): Standing
    {
        $hold = $this->latestHold((string) $organization->id);
        $today = CarbonImmutable::now()->startOfDay();

        if ($hold?->kind === AccountHold::FREEZE) {
            return new Standing(Standing::FROZEN, $this->daysOverdue((string) $organization->id, $today), true, $hold->reason);
        }

        if ($hold?->kind === AccountHold::RELEASE && $hold->released_until !== null && $hold->released_until->gte($today)) {
            return Standing::good();
        }

        if (! $this->settings->freezeEnabled()) {
            return Standing::good();
        }

        $policy = $this->settings->freezePolicy((string) $organization->id);

        if ($policy['exempt']) {
            return Standing::good();
        }

        $days = $this->daysOverdue((string) $organization->id, $today);

        return $days <= 0 ? Standing::good() : new Standing($this->stage($days, $policy), $days);
    }

    /**
     * The stage a number of days past due is in under a policy.
     *
     * @param  array{reminder_days: int, grace_days: int, restricted_days: int}  $policy
     */
    public function stage(int $daysOverdue, array $policy): string
    {
        $reminders = $policy['reminder_days'];
        $grace = $reminders + $policy['grace_days'];
        $restricted = $grace + $policy['restricted_days'];

        return match (true) {
            $daysOverdue <= 0 => Standing::GOOD,
            $daysOverdue <= $reminders => Standing::REMINDERS,
            $daysOverdue <= $grace => Standing::GRACE,
            $daysOverdue <= $restricted => Standing::RESTRICTED,
            default => Standing::FROZEN,
        };
    }

    /**
     * Freeze an account by hand, with why.
     *
     * @throws DomainException when it is frozen by hand already
     */
    public function freeze(Organization $organization, string $reason, ?string $actorId): AccountHold
    {
        if ($this->latestHold((string) $organization->id)?->kind === AccountHold::FREEZE) {
            throw new DomainException(__(':customer is frozen already.', ['customer' => $organization->name]));
        }

        return $this->hold($organization, AccountHold::FREEZE, $reason, null, $actorId);
    }

    /**
     * Lift a freeze by hand - one made by hand, or the policy's - until a
     * date, with why. After the date the policy decides again.
     *
     * @throws DomainException for a date in the past
     */
    public function unfreeze(Organization $organization, string $reason, CarbonImmutable $until, ?string $actorId): AccountHold
    {
        if ($until->lt(CarbonImmutable::now()->startOfDay())) {
            throw new DomainException(__('The freeze is lifted until today or a later day.'));
        }

        return $this->hold($organization, AccountHold::RELEASE, $reason, $until, $actorId);
    }

    /**
     * Every hand on the account, the latest first.
     *
     * @return Collection<int, AccountHold>
     */
    public function holds(string $organizationId): Collection
    {
        return AccountHold::acrossTenants()->where('org_id', $organizationId)->latest('created_at')->latest('id')->get();
    }

    /** How long the customer's oldest unpaid invoice is past due, in whole days. */
    public function daysOverdue(string $organizationId, CarbonImmutable $on): int
    {
        $due = Receivable::acrossTenants()
            ->where('org_id', $organizationId)
            ->whereIn('status', ReceivableStatus::outstandingValues())
            ->orderBy('due_date')
            ->value('due_date');

        return $due === null ? 0 : max(0, (int) CarbonImmutable::parse($due)->startOfDay()->diffInDays($on, false));
    }

    private function latestHold(string $organizationId): ?AccountHold
    {
        return AccountHold::acrossTenants()->where('org_id', $organizationId)->latest('created_at')->latest('id')->first();
    }

    private function hold(Organization $organization, string $kind, string $reason, ?CarbonImmutable $until, ?string $actorId): AccountHold
    {
        $hold = new AccountHold(['org_id' => $organization->id, 'kind' => $kind, 'reason' => mb_substr($reason, 0, 500), 'released_until' => $until?->toDateString()]);

        if ($actorId !== null) {
            $hold->forceFill(['created_by' => $actorId]);
        }

        $hold->save();

        $this->audit->logFor((string) $organization->id, $kind === AccountHold::FREEZE ? 'finance.account.frozen' : 'finance.account.unfrozen', $hold, [
            'reason' => $reason, 'until' => $until?->toDateString(),
        ]);

        return $hold;
    }
}
