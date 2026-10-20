<?php

declare(strict_types=1);

namespace App\Domains\Referral\Enums;

/**
 * Whether a reward reached the company it is for.
 */
enum RewardStatus: string
{
    case Granted = 'granted';

    /** Held back by a cap or by nothing to extend, until an admin grants it. */
    case Withheld = 'withheld';

    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Granted => __('Granted'),
            self::Withheld => __('Withheld'),
            self::Reversed => __('Reversed'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Granted => 'success',
            self::Withheld => 'warning',
            self::Reversed => 'danger',
        };
    }
}
