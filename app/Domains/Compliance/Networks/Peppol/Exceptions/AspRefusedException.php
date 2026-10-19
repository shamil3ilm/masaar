<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Exceptions;

use App\Domains\Compliance\Networks\Peppol\DTOs\AspError;
use RuntimeException;

/**
 * The provider refused the document itself. Sending the same bytes again
 * gets the same answer, so it is not retried.
 */
class AspRefusedException extends RuntimeException
{
    /** @var list<AspError> what it found wrong, rule by rule */
    public readonly array $errors;

    /**
     * @param  iterable<AspError|string|array<string, mixed>>  $errors  as the provider gave them
     */
    public function __construct(string $message, iterable $errors = [])
    {
        parent::__construct($message);

        $this->errors = AspError::listFrom($errors);
    }
}
