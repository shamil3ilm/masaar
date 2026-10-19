<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Engine\Contracts\Website as WebsitePort;

/** The United Arab Emirates on the public website. */
class Website implements WebsitePort
{
    /**
     * Sent over the Peppol network through an accredited service provider,
     * as the UAE's five-corner model has it: Masaar prepares the document,
     * the provider exchanges it. Masaar is not itself one.
     */
    public function offering(): string
    {
        return __('Peppol PINT AE e-invoices, exchanged through an accredited service provider.');
    }

    /** The PINT AE specification, itself a profile of UBL 2.1. */
    public function standards(): array
    {
        return ['Peppol PINT AE', 'UBL 2.1'];
    }
}
