<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation;

/**
 * Where a match stands: suggested and waiting for an admin; confirmed, its
 * money recorded; rejected by an admin; superseded by another match of the
 * same line, or by the line set aside; or reversed after it was confirmed.
 */
enum MatchStatus: string
{
    case Suggested = 'suggested';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => __('Suggested'),
            self::Confirmed => __('Confirmed'),
            self::Rejected => __('Rejected'),
            self::Superseded => __('Superseded'),
            self::Reversed => __('Reversed'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Suggested => 'info',
            self::Confirmed => 'success',
            self::Rejected => 'danger',
            self::Superseded, self::Reversed => 'neutral',
        };
    }
}
