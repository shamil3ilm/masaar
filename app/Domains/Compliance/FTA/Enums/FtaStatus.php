<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Enums;

enum FtaStatus: string
{
    case Draft            = 'draft';
    case Queued           = 'queued';
    case Submitted        = 'submitted';
    case Accepted         = 'accepted';
    case PendingReview    = 'pending_review';
    case Rejected         = 'rejected';
    case Failed           = 'failed';
    case Cancelled        = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Accepted, self::Cancelled], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft         => $next === self::Queued,
            self::Queued        => in_array($next, [self::Submitted, self::Failed, self::Cancelled], true),
            self::Submitted     => in_array($next, [self::Accepted, self::PendingReview, self::Rejected, self::Failed], true),
            self::PendingReview => in_array($next, [self::Accepted, self::Rejected], true),
            self::Rejected      => in_array($next, [self::Queued, self::Cancelled], true),  // allow retry
            self::Failed        => in_array($next, [self::Queued, self::Cancelled], true),
            default             => false,
        };
    }
}
