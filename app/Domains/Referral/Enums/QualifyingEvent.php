<?php

declare(strict_types=1);

namespace App\Domains\Referral\Enums;

/**
 * What a referred company has to do before either side is rewarded.
 */
enum QualifyingEvent: string
{
    /** A platform admin approves the company. */
    case Approval = 'approval';

    /** The company pays its first invoice. */
    case FirstPaid = 'first_paid';

    public function label(): string
    {
        return match ($this) {
            self::Approval => __('When the company is approved'),
            self::FirstPaid => __('When the company pays its first invoice'),
        };
    }
}
