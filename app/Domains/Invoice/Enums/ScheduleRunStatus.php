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
    // An instalment of a plan delivered up front: a payment due against
    // the plan's one invoice, not an invoice of its own.
    case Due = 'due';

    /** Whether the period is settled: an invoice exists for it, it was skipped, or it is a payment due. */
    public function isDone(): bool
    {
        return in_array($this, [self::Drafted, self::Issued, self::Skipped, self::Due], true);
    }
}
