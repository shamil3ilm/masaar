<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Exceptions;

use RuntimeException;

class UnsupportedJurisdictionException extends RuntimeException
{
    public static function for(string $jurisdiction): self
    {
        return new self("No compliance engine registered for jurisdiction: {$jurisdiction}");
    }
}
