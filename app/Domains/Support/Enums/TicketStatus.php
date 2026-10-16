<?php

declare(strict_types=1);

namespace App\Domains\Support\Enums;

/**
 * Whose move it is.
 */
enum TicketStatus: string
{
    /** Waiting on support. */
    case Open = 'open';

    /** Support answered and waits for the customer. */
    case Waiting = 'waiting';

    /** Done. A reply from the customer opens it again. */
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Waiting => __('Waiting on customer'),
            self::Resolved => __('Resolved'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Waiting => 'info',
            self::Resolved => 'success',
        };
    }
}
