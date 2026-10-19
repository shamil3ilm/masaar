<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Engine\Contracts\Sectors as SectorsPort;

/**
 * The business types UAE e-invoicing serves.
 *
 * It covers business transactions - to businesses and to government - and
 * not sales to consumers: "Any supplies to or from natural persons who are
 * not in Business are not within the scope of Electronic Invoicing ... There
 * is no obligation for the supplier or the agent to issue an Electronic
 * Invoice in relation to a supply that is made to a consumer" (UAE
 * Electronic Invoicing Guidelines v1.1, 1 June 2026, §6.2, p14).
 *
 * So a shop or a supermarket, whose sales are to consumers, has nothing to
 * e-invoice and is not offered; a pharmacy, a clinic, a school or an online
 * shop is, with a note that only its sales to businesses and government
 * are e-invoiced. A mixed business chooses what it mostly is.
 */
class Sectors implements SectorsPort
{
    /** Businesses that sell to consumers and to no one else. */
    private const CONSUMER_ONLY = ['retail', 'supermarket'];

    /** Businesses that sell to consumers as well as to businesses. */
    private const PARTLY_CONSUMER = ['pharmacy', 'clinic_hospital', 'school', 'ecommerce'];

    public function omitted(): array
    {
        return self::CONSUMER_ONLY;
    }

    public function note(string $key): ?string
    {
        return in_array($key, self::PARTLY_CONSUMER, true)
            ? __('In the UAE only sales to businesses and government are e-invoiced; sales to consumers are outside it (UAE Electronic Invoicing Guidelines, section 6.2).')
            : null;
    }
}
