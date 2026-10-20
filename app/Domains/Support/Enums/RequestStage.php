<?php

declare(strict_types=1);

namespace App\Domains\Support\Enums;

/**
 * Where a customization or a feature request has got to. Each move is a
 * message on its ticket, with a note the customer reads.
 */
enum RequestStage: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Released = 'released';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => __('Submitted'),
            self::UnderReview => __('Under review'),
            self::Planned => __('Planned'),
            self::InProgress => __('In progress'),
            self::Released => __('Released'),
            self::Declined => __('Declined'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Submitted => 'neutral',
            self::UnderReview => 'info',
            self::Planned => 'accent',
            self::InProgress => 'warning',
            self::Released => 'success',
            self::Declined => 'danger',
        };
    }

    /** Finished one way or the other: the conversation is resolved with it. */
    public function final(): bool
    {
        return $this === self::Released || $this === self::Declined;
    }
}
