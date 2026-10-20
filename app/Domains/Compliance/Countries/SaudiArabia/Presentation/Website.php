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

    /**
     * A standard (B2B) tax invoice: the buyer's VAT number and national
     * address that a tax invoice carries, the buyer's name and the line in
     * Arabic beside the English (E-Invoicing Detailed Guidelines p63), and
     * one line at the standard rate. The same invoice the integration
     * guides' examples are built on.
     */
    public function apiExample(string $today): array
    {
        return [
            'invoice_number' => 'INV-1001',
            'type' => 'standard',
            'issue_date' => $today,
            'supply_date' => $today,
            'currency' => 'SAR',
            'buyer_name' => 'Beta Industries',
            'buyer_name_ar' => 'شركة بيتا للصناعات',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => [
                'street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj',
                'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA',
            ],
            'lines' => [
                ['description' => 'Consulting', 'description_ar' => 'خدمات استشارية', 'quantity' => 1, 'unit_price' => '1000.00'],
            ],
        ];
    }
}
