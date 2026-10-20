<?php

declare(strict_types=1);

namespace App\Domains\Referral\Enums;

/**
 * What one side of a referral is given.
 */
enum RewardKind: string
{
    /** A percentage off the next invoice. */
    case PercentOff = 'percent_off';

    /** A fixed credit, in the currency the company is billed in. */
    case Credit = 'credit';

    /** Months added to the plan: its licence expires later. */
    case FreeMonths = 'free_months';

    /** Nothing. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::PercentOff => __('Percentage off the next invoice'),
            self::Credit => __('Fixed credit'),
            self::FreeMonths => __('Free months'),
            self::None => __('Nothing'),
        };
    }
}
