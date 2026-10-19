<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Engine\Contracts\Website as WebsitePort;

/** Saudi Arabia on the public website. */
class Website implements WebsitePort
{
    /**
     * Phase 2, integration: a standard tax invoice is cleared by ZATCA
     * before it reaches the buyer, a simplified one reported within 24 hours
     * (E-Invoicing Detailed Guidelines, clearance and reporting).
     */
    public function offering(): string
    {
        return __('ZATCA Fatoora Phase 2: tax invoices cleared and simplified invoices reported, as ZATCA requires.');
    }

    /**
     * The XML is UBL 2.1 as the XML Implementation Standard profiles it, and
     * the invoice handed to a buyer a PDF/A-3 with that XML inside.
     */
    public function standards(): array
    {
        return [__('ZATCA XML Implementation Standard'), 'UBL 2.1', 'PDF/A-3'];
    }
}
