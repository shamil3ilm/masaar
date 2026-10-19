<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Engine\Contracts\Website as WebsitePort;
use App\Domains\Compliance\Engine\DTOs\SampleInvoice;
use App\Domains\Compliance\Engine\DTOs\SampleQr;

/**
 * The United Arab Emirates on the public website.
 *
 * A PINT AE invoice is a document exchanged between the parties' service
 * providers, not a printed copy with a code to scan, so the sample carries
 * no QR code.
 */
class Website implements WebsitePort
{
    public function sampleQr(SampleInvoice $sample): ?SampleQr
    {
        return null;
    }
}
