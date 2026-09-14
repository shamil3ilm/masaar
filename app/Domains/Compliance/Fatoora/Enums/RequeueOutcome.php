<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Enums;

/**
 * What happened when an operator asked for an offline item to be retried.
 */
enum RequeueOutcome
{
    case Requeued;

    case NotFound;

    /** The item exists but has not failed, so there is nothing to retry. */
    case NotFailed;
}
