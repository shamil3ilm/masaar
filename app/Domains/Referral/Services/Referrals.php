<?php

declare(strict_types=1);

namespace App\Domains\Referral\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Referral\Enums\QualifyingEvent;
use App\Domains\Referral\Enums\ReferralStatus;
use App\Domains\Referral\Models\Referral;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The referral programme: a company signs up with another's code, and once
 * it qualifies both sides are rewarded.
 *
 * signed up   at sign-up, with the referrer's terms as they stood - a later
 *             change to the programme applies to new referrals only. One
 *             referrer per company. The abuse checks (SelfReferral) refuse
 *             a company that is the referrer itself.
 * approved    a platform admin approved the company.
 * qualified   the terms' event happened: the approval, or its first paid
 *             invoice. The checks run again, since a VAT number often
 *             arrives after sign-up.
 * rewarded    both sides have their rewards (ReferralRewards). One held
 *             back by a limit leaves it qualified until an admin approves.
 *
 * An admin can approve one (a company approved some other way, or a reward
 * held back), adjust a reward and reverse the whole referral. Every step is
 * in the audit log.
 */
class Referrals
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly ReferralProgramme $programme,
        private readonly ReferralCodes $codes,
        private readonly SelfReferral $abuse,
        private readonly ReferralRewards $rewards,
        private readonly AuditService $audit,
    ) {}

    /** Whether a code typed at sign-up is one the programme runs for. */
    public function accepts(?string $code): bool
    {
        $found = $this->codes->find($code);

        return $found !== null && $this->programme->runsFor((string) $found->org_id);
    }

    /**
     * A company signed up with a code. Nothing when there is no code, it is
     * unknown or the programme does not run for its referrer; a refused
     * referral when the company is the referrer itself.
     */
    public function recordSignup(?string $code, Organization $organization, ?User $user): ?Referral
    {
        $found = $this->codes->find($code);

        if ($found === null || ! $this->programme->runsFor((string) $found->org_id)) {
            return null;
        }

        $existing = Referral::query()->where('org_id', $organization->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $referrer = Organization::query()->findOrFail($found->org_id);
        $refusal = $this->abuse->reason($referrer, $organization, $user);

        $referral = Referral::query()->create([
            'referrer_org_id' => $referrer->id,
            'org_id' => $organization->id,
            'code' => $found->code,
            'status' => $refusal === null ? ReferralStatus::SignedUp : ReferralStatus::Refused,
            'terms' => $this->programme->terms((string) $referrer->id)->toArray(),
            'refusal_reason' => $refusal,
        ]);

        $this->record($refusal === null ? 'referral.signed_up' : 'referral.refused', $referral, ['reason' => $refusal], $user?->id);

        return $referral;
    }

    /** The company was approved: the referral moves on, and may qualify. */
    public function organizationApproved(string $organizationId, ?string $actorId): void
    {
        $referral = $this->of($organizationId);

        if ($referral === null || $referral->status !== ReferralStatus::SignedUp) {
            return;
        }

        $this->markApproved($referral, $actorId);
    }

    /** The company paid an invoice: the first one qualifies it, where the terms say so. */
    public function invoicePaid(string $organizationId): void
    {
        $referral = $this->of($organizationId);

        if ($referral !== null && in_array($referral->status, [ReferralStatus::SignedUp, ReferralStatus::Approved], true)
            && $referral->agreedTerms()->qualifyingEvent === QualifyingEvent::FirstPaid) {
            $this->qualify($referral, null);
        }
    }

    /**
     * An admin moves it on: a company approved some other way is approved;
     * one held back by a limit has its rewards granted past it.
     *
     * @throws DomainException when there is nothing to approve
     */
    public function approve(Referral $referral, string $actorId): void
    {
        match ($referral->status) {
            ReferralStatus::SignedUp => $this->markApproved($referral, $actorId),
            ReferralStatus::Qualified => $this->reward($referral, $actorId, pastLimits: true),
            default => throw new DomainException('This referral is '.strtolower($referral->status->label()).': there is nothing to approve.'),
        };
    }

    /**
     * Taken back by an admin: every reward reversed - credit not yet used,
     * months off the plan - and the referral with them.
     *
     * @throws DomainException when a reward's credit was used on an invoice
     */
    public function reverse(Referral $referral, string $reason, string $actorId): void
    {
        if ($referral->status === ReferralStatus::Reversed) {
            return;
        }

        DB::transaction(function () use ($referral, $reason, $actorId): void {
            foreach ($referral->rewards()->get() as $reward) {
                $this->rewards->reverse($reward, $reason, $actorId);
            }

            $referral->forceFill([
                'status' => ReferralStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $actorId,
                'reversal_reason' => mb_substr($reason, 0, 500),
            ])->save();
        });

        $this->record('referral.reversed', $referral, ['reason' => $reason], $actorId);
    }

    /**
     * The referrals an organization made, newest first.
     *
     * @return Collection<int, Referral>
     */
    public function madeBy(string $organizationId): Collection
    {
        return Referral::query()->with('rewards')->where('referrer_org_id', $organizationId)->latest()->get();
    }

    /** The referral that brought an organization, if one did. */
    public function of(string $organizationId): ?Referral
    {
        return Referral::query()->where('org_id', $organizationId)->first();
    }

    public function find(string $referralId): ?Referral
    {
        return Referral::query()->with(['referrer', 'organization', 'rewards'])->find($referralId);
    }

    /**
     * Every referral, for the console, newest first.
     *
     * @return LengthAwarePaginator<int, Referral>
     */
    public function page(?ReferralStatus $status): LengthAwarePaginator
    {
        return Referral::query()
            ->with(['referrer', 'organization', 'rewards'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(self::PER_PAGE);
    }

    public function organization(string $organizationId): ?Organization
    {
        return Organization::query()->find($organizationId);
    }

    /**
     * Organizations by name, or by the referral code they hand out, for an
     * admin choosing whose terms to set.
     *
     * @return Collection<int, Organization>
     */
    public function organizations(string $search): Collection
    {
        $code = $this->codes->find($search);
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        return Organization::query()
            ->where(fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                ->when($code !== null, fn ($query) => $query->orWhere('id', $code->org_id)))
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    private function markApproved(Referral $referral, ?string $actorId): void
    {
        $referral->forceFill(['status' => ReferralStatus::Approved, 'approved_at' => now()])->save();
        $this->record('referral.approved', $referral, [], $actorId);

        if ($referral->agreedTerms()->qualifyingEvent === QualifyingEvent::Approval) {
            $this->qualify($referral, $actorId);
        }
    }

    /**
     * The qualifying event happened: checked again, then rewarded.
     */
    private function qualify(Referral $referral, ?string $actorId): void
    {
        $referrer = $referral->referrer()->first();
        $organization = $referral->organization()->first();
        $refusal = $referrer !== null && $organization !== null ? $this->abuse->reason($referrer, $organization) : null;

        if ($refusal !== null) {
            $referral->forceFill(['status' => ReferralStatus::Refused, 'refusal_reason' => $refusal])->save();
            $this->record('referral.refused', $referral, ['reason' => $refusal], $actorId);

            return;
        }

        $referral->forceFill(['status' => ReferralStatus::Qualified, 'qualified_at' => now()])->save();
        $this->record('referral.qualified', $referral, [], $actorId);

        $this->reward($referral, $actorId, pastLimits: false);
    }

    private function reward(Referral $referral, ?string $actorId, bool $pastLimits): void
    {
        if ($this->rewards->grantAll($referral, $actorId, $pastLimits)) {
            $referral->forceFill(['status' => ReferralStatus::Rewarded, 'rewarded_at' => now()])->save();
            $this->record('referral.rewarded', $referral, [], $actorId);
        }
    }

    /**
     * Under the referrer, whose programme it is.
     *
     * @param  array<string, mixed>  $details
     */
    private function record(string $action, Referral $referral, array $details, ?string $actorId): void
    {
        $this->audit->logChange($action, 'Referral', (string) $referral->id, null,
            ['status' => $referral->status->value, 'referred_org_id' => $referral->org_id, ...array_filter($details)],
            [], $actorId, (string) $referral->referrer_org_id);
    }
}
