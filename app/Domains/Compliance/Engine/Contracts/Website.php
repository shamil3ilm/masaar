<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\SampleInvoice;
use App\Domains\Compliance\Engine\DTOs\SampleQr;

/**
 * What the public website says and shows about a country.
 *
 * The home page asks every registered module and never which country it is,
 * so a country added to the engine appears there without a shared view
 * changing. Nothing here is a real document: a sample is labelled as one.
 */
interface Website
{
    /**
     * The QR code the country's invoices carry, drawn for the home page's
     * sample invoice: its figures, the seller tax number the country uses for
     * samples, and the payload a phone reads. Null where the country's
     * invoices carry no QR code.
     */
    public function sampleQr(SampleInvoice $sample): ?SampleQr;
}
