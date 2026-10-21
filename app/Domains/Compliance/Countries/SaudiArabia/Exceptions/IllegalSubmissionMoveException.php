<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Exceptions;

use LogicException;

/**
 * A submission asked to make a move its table (SubmissionState) does not
 * have. A bug in the caller, never a filing outcome: nothing is written.
 */
final class IllegalSubmissionMoveException extends LogicException
{
    public static function between(?string $from, string $to, ?string $submissionId = null): self
    {
        return new self(sprintf(
            "A submission%s cannot move from '%s' to '%s'.",
            $submissionId !== null ? " ({$submissionId})" : '',
            $from ?? 'nothing',
            $to,
        ));
    }
}
