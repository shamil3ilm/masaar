<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * One period of a schedule: claimed (pending), its invoice drafted, issued,
 * failed for now, or skipped on purpose.
 */
enum ScheduleRunStatus: string
{
    case Pending = 'pending';
    case Drafted = 'drafted';
    case Issued = 'issued';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /** Whether the period is settled: an invoice exists for it, or it was skipped. */
    public function isDone(): bool
    {
        return in_array($this, [self::Drafted, self::Issued, self::Skipped], true);
    }
}
