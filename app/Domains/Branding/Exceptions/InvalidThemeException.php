<?php

declare(strict_types=1);

namespace App\Domains\Branding\Exceptions;

use InvalidArgumentException;

/**
 * A theme that cannot be kept, with every reason at once.
 */
final class InvalidThemeException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
