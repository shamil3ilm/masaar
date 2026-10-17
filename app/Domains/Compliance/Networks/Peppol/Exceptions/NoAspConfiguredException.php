<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Exceptions;

use RuntimeException;

/**
 * No accredited service provider is connected to this deployment.
 *
 * Not a failure of the document: it stays ready, and its XML can be
 * downloaded and uploaded through the provider's own portal.
 */
class NoAspConfiguredException extends RuntimeException
{
    public static function make(): self
    {
        return new self('No accredited service provider is configured; the document waits for a manual upload.');
    }
}
