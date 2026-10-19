<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Engine\Contracts\Sectors as SectorsPort;

/**
 * Every business type is served in Saudi Arabia: a sale to a consumer is
 * invoiced too, on a simplified tax invoice reported to ZATCA (E-Invoicing
 * Detailed Guidelines, the simplified invoice and its reporting).
 */
class Sectors implements SectorsPort
{
    public function omitted(): array
    {
        return [];
    }

    public function note(string $key): ?string
    {
        return null;
    }
}
