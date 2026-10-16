<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Exceptions;

use RuntimeException;

/**
 * The provider refused the document itself. Sending the same bytes again
 * gets the same answer, so it is not retried.
 */
class AspRefusedException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
