<?php

declare(strict_types=1);

namespace App\Domains\Referral\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\DTOs\AvailableCredit;
use App\Domains\Billing\Services\AccountCredits;
use App\Domains\Licensing\Services\LicenseManagementService;
use App\Domains\Referral\DTOs\ReferralTerms;
use App\Domains\Referral\Enums\RewardKind;
use App\Domains\Referral\Enums\RewardStatus;
use App\Domains\Referral\Models\Referral;
use App\Domains\Referral\Models\ReferralReward;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Giving each side of a qualified referral what its terms say.
 *
 * A percentage off or a fixed credit becomes an account credit (Billing's
 * CreditSource), which the next invoice redeems; free months move the
 * plan's licence expiry on. The limits hold unless an admin grants past
 * them: a referrer's rewards in a calendar year (yearlyCap), and the
 * usable credit a company may hold in a currency (creditCap) - a credit is
 * cut to fit, or withheld when there is no room. A reward that cannot be
 * given is kept, withheld, with why.
 *
 * Every grant, adjustment and reversal is in the audit log, under the
 * company that receives the reward.
 */
class ReferralRewards
{
    public const SOURCE = 'referral';

    public function __construct(
        private readonly AccountCredits $credits,
        private readonly LicenseManagementService $licences,
        private readonly AuditService $audit,
    ) {}

    /**
     * Both sides' rewards: made once, given now unless held back.
     *
     * @return bool whether nothing is withheld
     */
    public function grantAll(Referral $referral, ?string $actorId, bool $pastLimits = false): bool
    {
        $terms = $referral->agreedTerms();

        foreach ([ReferralReward::REFERRER, ReferralReward::REFERRED] as $side) {
            $reward = $referral->rewards()->where('side', $side)->first() ?? $this->make($referral, $terms, $side);

            if ($reward?->status === RewardStatus::Withheld) {
                $this->grant($reward, $terms, $actorId, $pastLimits);
            }
        }

        return ! $referral->rewards()->where('status', RewardStatus::Withheld)->exists();
    }

    /**
     * A new value for a reward: a percentage, a sum, or a number of months.
     * A granted one changes what was given - its credit, or the plan by the
     * difference in months; a withheld one is given at it later.
     *
     * @throws DomainException for a reversed reward, or a credit already used
     */
    public function adjust(ReferralReward $reward, string $value, string $reason, string $actorId): void
    {
        if ($reward->status === RewardStatus::Reversed) {
            throw new DomainException('A reversed reward cannot be adjusted.');
        }

        if ($reward->kind === RewardKind::PercentOff && (float) $value > 100) {
            throw new DomainException('A percentage off is at most 100.');
        }

        $before = $this->values($reward);
        $field = match ($reward->kind) {
            RewardKind::PercentOff => 'percent',
            RewardKind::Credit => 'amount',
            default => 'months',
        };

        DB::transaction(function () use ($reward, $value, $actorId, $field): void {
            if ($reward->status === RewardStatus::Granted) {
                if ($reward->credit_id !== null) {
                    $this->credits->adjust((string) $reward->credit_id, $value, 'Referral reward adjusted', $actorId);
                } elseif ($reward->kind === RewardKind::FreeMonths) {
                    $this->extend($reward, (int) $value - (int) $reward->months, $actorId)
                        ?? throw new DomainException('This company has no plan with an end date to change.');
                }
            }

            $reward->forceFill([$field => $field === 'months' ? (int) $value : $value])->save();
        });

        $this->record('referral.reward.adjusted', $reward, $before, [...$this->values($reward), 'reason' => $reason], $actorId);
    }

    /**
     * Taken back: its credit reversed, or the months taken off the plan.
     *
     * @throws DomainException when its credit was used on an invoice
     */
    public function reverse(ReferralReward $reward, string $reason, string $actorId): void
    {
        if ($reward->status === RewardStatus::Reversed) {
            return;
        }

        DB::transaction(function () use ($reward, $reason, $actorId): void {
            if ($reward->status === RewardStatus::Granted) {
                if ($reward->credit_id !== null) {
                    $this->credits->reverse((string) $reward->credit_id, $reason, $actorId);
                } elseif ($reward->kind === RewardKind::FreeMonths && $reward->licence_id !== null) {
                    $this->extend($reward, -1 * (int) $reward->months, $actorId);
                }
            }

            $reward->forceFill([
                'status' => RewardStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $actorId,
                'reversal_reason' => mb_substr($reason, 0, 500),
            ])->save();
        });

        $this->record('referral.reward.reversed', $reward, null, ['reason' => $reason], $actorId);
    }

    /**
     * The reward a side's terms give, withheld until granted; null when they
     * give nothing.
     */
    private function make(Referral $referral, ReferralTerms $terms, string $side): ?ReferralReward
    {
        $given = $terms->side($side);
        $organizationId = (string) ($side === ReferralReward::REFERRER ? $referral->referrer_org_id : $referral->org_id);
        $currency = $given->kind === RewardKind::Credit ? $this->credits->currencyOf($organizationId) : null;

        if ($given->kind === RewardKind::None) {
            return null;
        }

        return ReferralReward::query()->create([
            'referral_id' => $referral->id,
            'org_id' => $organizationId,
            'side' => $side,
            'kind' => $given->kind,
            'percent' => $given->kind === RewardKind::PercentOff ? $given->percent : null,
            'amount' => $currency !== null ? $given->creditIn($currency) : null,
            'currency' => $currency,
            'months' => $given->kind === RewardKind::FreeMonths ? $given->months : null,
            'status' => RewardStatus::Withheld,
        ]);
    }

    /**
     * Give a withheld reward, unless a limit or a missing plan holds it.
     */
    private function grant(ReferralReward $reward, ReferralTerms $terms, ?string $actorId, bool $pastLimits): void
    {
        $held = $this->held($reward, $terms, $pastLimits);

        if ($held !== null) {
            $reward->forceFill(['withheld_reason' => $held])->save();
            $this->record('referral.reward.withheld', $reward, null, ['reason' => $held], $actorId);

            return;
        }

        DB::transaction(function () use ($reward, $terms, $actorId, $pastLimits): void {
            $description = 'Referral reward: '.$reward->describe();
            $expires = $terms->expiryDays !== null ? CarbonImmutable::now()->addDays($terms->expiryDays) : null;

            if ($reward->kind === RewardKind::PercentOff) {
                $credit = $this->credits->grant((string) $reward->org_id, AvailableCredit::PERCENT, null, (string) $reward->percent, null,
                    $description, self::SOURCE, (string) $reward->id, $expires, $actorId);
                $reward->forceFill(['credit_id' => $credit->id]);
            } elseif ($reward->kind === RewardKind::Credit) {
                $amount = (string) $this->fitted($reward, $terms, $pastLimits);
                $credit = $this->credits->grant((string) $reward->org_id, AvailableCredit::AMOUNT, $amount, null, (string) $reward->currency,
                    $description, self::SOURCE, (string) $reward->id, $expires, $actorId);
                $reward->forceFill(['amount' => $amount, 'credit_id' => $credit->id]);
            } elseif ($reward->kind === RewardKind::FreeMonths) {
                $reward->forceFill(['licence_id' => $this->extend($reward, (int) $reward->months, $actorId)]);
            }

            $reward->forceFill(['status' => RewardStatus::Granted, 'granted_at' => now(), 'withheld_reason' => null])->save();
        });

        $this->record('referral.reward.granted', $reward, null, $this->values($reward), $actorId);
    }

    /** Why a reward cannot be given now, or null when it can. */
    private function held(ReferralReward $reward, ReferralTerms $terms, bool $pastLimits): ?string
    {
        if ($reward->kind === RewardKind::Credit && $reward->amount === null) {
            return 'The terms give no credit in '.$reward->currency.', the currency this company is billed in.';
        }

        if ($reward->kind === RewardKind::FreeMonths && ! $this->licences->hasPlanEnd((string) $reward->org_id)) {
            return 'This company has no plan with an end date to extend.';
        }

        if ($pastLimits) {
            return null;
        }

        if ($reward->side === ReferralReward::REFERRER && $terms->yearlyCap !== null) {
            $thisYear = ReferralReward::query()
                ->where('org_id', $reward->org_id)
                ->where('side', ReferralReward::REFERRER)
                ->where('status', RewardStatus::Granted)
                ->where('granted_at', '>=', now()->startOfYear())
                ->count();

            if ($thisYear >= $terms->yearlyCap) {
                return 'The referrer has had its '.$terms->yearlyCap.' rewards for this year.';
            }
        }

        if ($reward->kind === RewardKind::Credit && $this->fitted($reward, $terms, false) === null) {
            return 'The company holds as much credit as the cap allows.';
        }

        return null;
    }

    /**
     * The credit that fits under the cap: all of it, part, or none (null).
     */
    private function fitted(ReferralReward $reward, ReferralTerms $terms, bool $pastLimits): ?string
    {
        $amount = (float) $reward->amount;

        if ($pastLimits || $terms->creditCap === null) {
            return $this->money($amount);
        }

        $room = (float) $terms->creditCap - (float) $this->credits->outstanding((string) $reward->org_id, (string) $reward->currency);

        return $room <= 0 ? null : $this->money(min($amount, $room));
    }

    private function extend(ReferralReward $reward, int $months, ?string $actorId): ?string
    {
        return $months === 0 ? $reward->licence_id : $this->licences->extendPlan((string) $reward->org_id, $months, $actorId);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(ReferralReward $reward): array
    {
        return array_filter([
            'referral_id' => $reward->referral_id,
            'side' => $reward->side,
            'kind' => $reward->kind->value,
            'percent' => $reward->percent,
            'amount' => $reward->amount,
            'currency' => $reward->currency,
            'months' => $reward->months,
            'credit_id' => $reward->credit_id,
            'licence_id' => $reward->licence_id,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function record(string $action, ReferralReward $reward, ?array $before, array $after, ?string $actorId): void
    {
        $this->audit->logChange($action, 'ReferralReward', (string) $reward->id, $before, $after, [], $actorId, (string) $reward->org_id);
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
