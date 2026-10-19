<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * Where a schedule stands. Only an active one runs; a paused one can be
 * resumed; a cancelled or completed one is finished and stays as a record
 * of what it billed.
 */
enum ScheduleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function isFinished(): bool
    {
        return $this === self::Cancelled || $this === self::Completed;
    }
}
