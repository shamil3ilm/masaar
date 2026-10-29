<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Enums;

/**
 * What happened when an operator asked for a document waiting to be filed
 * to be tried again (FilingOperations::requeue).
 */
enum RequeueOutcome
{
    case Requeued;

    case NotFound;

    /** The item exists but has not failed, so there is nothing to retry. */
    case NotFailed;
}
