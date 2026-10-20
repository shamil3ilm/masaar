<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Engine\Contracts\Website as WebsitePort;

/** The United Arab Emirates on the public website. */
class Website implements WebsitePort
{
    /**
     * Sent over the Peppol network through an accredited service provider,
     * as the UAE's five-corner model has it: the platform prepares the
     * document, the provider exchanges it. The platform is not itself one.
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

    /**
     * A tax invoice to a VAT-registered buyer reached on the network: the
     * buyer's TRN, network endpoint and emirate, a due date and payment
     * means, the transaction-type flags all off, and one line at the
     * standard rate. The same invoice the integration guides' examples are
     * built on, which DocumentedExamplesTest checks against the PINT AE
     * rules.
     */
    public function apiExample(string $today): array
    {
        return [
            'invoice_number' => 'INV-1001',
            'document_type' => 'invoice',
            'document_category' => 'tax',
            'issue_date' => $today,
            'due_date' => date('Y-m-d', (int) strtotime($today.' +30 days')),
            'currency' => 'AED',
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi', 'country_code' => 'AE'],
            'transaction_flags' => '00000000',
            'lines' => [
                ['item_name' => 'Consulting', 'quantity' => 1, 'unit_price' => 500, 'tax_category' => 'S', 'tax_rate' => 5],
            ],
        ];
    }
}
