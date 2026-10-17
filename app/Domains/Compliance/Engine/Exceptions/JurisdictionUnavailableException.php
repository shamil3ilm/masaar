<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Exceptions;

use DomainException;

/**
 * An organization's country this deployment does not issue in: no module is
 * registered for it, or its module is switched off. Raised before anything
 * is written.
 */
final class JurisdictionUnavailableException extends DomainException
{
    private function __construct(string $message, public readonly string $country)
    {
        parent::__construct($message);
    }

    public static function unknown(string $country): self
    {
        return new self("No jurisdiction is registered for country {$country}, so its invoices cannot be issued here.", $country);
    }

    public static function disabled(string $country, string $name): self
    {
        return new self("This deployment does not issue invoices in {$name} ({$country}).", $country);
    }

    public static function noDefault(): self
    {
        return new self('The organization has no country, and no jurisdiction is registered as the default.', '');
    }
}
