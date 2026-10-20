<?php

declare(strict_types=1);

namespace App\Domains\Referral\Enums;

/**
 * Where a referral has got to.
 */
enum ReferralStatus: string
{
    /** The company signed up with the code. */
    case SignedUp = 'signed_up';

    /** A platform admin approved the company. */
    case Approved = 'approved';

    /** The qualifying event happened; a reward waits for an admin. */
    case Qualified = 'qualified';

    /** Both sides have what the terms give them. */
    case Rewarded = 'rewarded';

    /** The abuse checks found the company is the referrer itself. */
    case Refused = 'refused';

    /** An admin took it back, rewards and all. */
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::SignedUp => __('Signed up'),
            self::Approved => __('Approved'),
            self::Qualified => __('Qualified'),
            self::Rewarded => __('Rewarded'),
            self::Refused => __('Refused'),
            self::Reversed => __('Reversed'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::SignedUp => 'neutral',
            self::Approved, self::Qualified => 'info',
            self::Rewarded => 'success',
            self::Refused, self::Reversed => 'danger',
        };
    }

    /** Still on its way to a reward. */
    public function open(): bool
    {
        return in_array($this, [self::SignedUp, self::Approved, self::Qualified], true);
    }
}
