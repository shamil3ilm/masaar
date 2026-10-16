<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Exceptions;

use DomainException;

/**
 * An invoice the country's rules would refuse, so it was not issued: it is
 * still a draft, with no number taken.
 */
final class IssuanceRefusedException extends DomainException
{
    /**
     * @param  list<string>  $problems  every one at once, as sentences a person can act on
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(__('The invoice was not issued:')."\n- ".implode("\n- ", $problems));
    }
}
