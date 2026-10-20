<?php

declare(strict_types=1);

namespace App\Domains\Referral\DTOs;

use App\Domains\Referral\Enums\RewardKind;

/**
 * What one side of a referral - the referrer, or the company referred - is
 * given: a percentage off the next invoice, a fixed credit (an amount per
 * currency, the company's own used), months on its plan, or nothing.
 */
final readonly class SideReward
{
    /**
     * @param  array<string, string>  $credit  currency => amount
     */
    public function __construct(
        public RewardKind $kind,
        public ?int $percent = null,
        public array $credit = [],
        public ?int $months = null,
    ) {}

    /** The credit in a currency, or null when the terms give none in it. */
    public function creditIn(string $currency): ?string
    {
        $amount = $this->credit[$currency] ?? null;

        return is_numeric($amount) && (float) $amount > 0 ? number_format((float) $amount, 2, '.', '') : null;
    }

    /** What it is, as a person reads it. */
    public function describe(): string
    {
        return match ($this->kind) {
            RewardKind::PercentOff => __(':percent% off the next invoice', ['percent' => (int) $this->percent]),
            RewardKind::Credit => __('A credit of :amounts', ['amounts' => implode(' / ', array_map(
                fn (string $currency, string $amount): string => number_format((float) $amount, 2).' '.$currency,
                array_keys($this->credit),
                $this->credit,
            ))]),
            RewardKind::FreeMonths => (int) $this->months === 1 ? __('One free month') : __(':count free months', ['count' => (int) $this->months]),
            RewardKind::None => __('Nothing'),
        };
    }
}
