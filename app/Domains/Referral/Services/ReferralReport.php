<?php

declare(strict_types=1);

namespace App\Domains\Referral\Services;

use App\Domains\Billing\Services\AccountCredits;
use App\Domains\Referral\Enums\ReferralStatus;
use App\Domains\Referral\Enums\RewardKind;
use App\Domains\Referral\Enums\RewardStatus;
use App\Domains\Referral\Models\Referral;
use App\Domains\Referral\Models\ReferralReward;

/**
 * The programme in numbers: referrals by status, the rewards granted by
 * kind, the free months given, and its credit - granted, redeemed on
 * invoices and still outstanding - by currency.
 */
class ReferralReport
{
    public function __construct(
        private readonly AccountCredits $credits,
    ) {}

    /**
     * @return array{statuses: array<string, int>, rewards: array<string, int>, withheld: int, months: int, credits: array{amounts: array<string, array{granted: string, redeemed: string, outstanding: string}>, percent: array{granted: int, redeemed: int, outstanding: int}}}
     */
    public function summary(): array
    {
        $statuses = Referral::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $granted = ReferralReward::query()->where('status', RewardStatus::Granted)->get(['kind', 'months']);

        return [
            'statuses' => array_combine(
                array_map(fn (ReferralStatus $status): string => $status->value, ReferralStatus::cases()),
                array_map(fn (ReferralStatus $status): int => (int) ($statuses[$status->value] ?? 0), ReferralStatus::cases()),
            ),
            'rewards' => array_combine(
                array_map(fn (RewardKind $kind): string => $kind->value, RewardKind::cases()),
                array_map(fn (RewardKind $kind): int => $granted->where('kind', $kind)->count(), RewardKind::cases()),
            ),
            'withheld' => ReferralReward::query()->where('status', RewardStatus::Withheld)->count(),
            'months' => (int) $granted->where('kind', RewardKind::FreeMonths)->sum('months'),
            'credits' => $this->credits->totals(ReferralRewards::SOURCE),
        ];
    }
}
