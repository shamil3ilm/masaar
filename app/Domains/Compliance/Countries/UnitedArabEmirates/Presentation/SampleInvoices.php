<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeSellerDetails;
use App\Domains\Compliance\Engine\DTOs\PrintSample;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;

/**
 * The tax invoice a UAE invoice template's preview draws: standard rated at
 * 5%, in dirhams, to a buyer not yet on electronic invoicing - the case a
 * printed tax invoice is required for (Electronic Invoicing Guidelines v1.1,
 * 10.2.2) - built in memory and never saved.
 *
 * The seller is the organization's own when there is one, with its UAE
 * settings; the platform's preview gets a made-up company. The number says
 * "sample".
 */
final class SampleInvoices
{
    /** item name, description, quantity, unit, net unit price */
    private const LINES = [
        ['Consulting', 'Consulting services, October', '2', 'DAY', '500.00'],
        ['Fountain pen', 'Fountain pen, blue ink', '20', 'H87', '5.00'],
        ['Delivery', 'Delivery within Dubai', '1', 'C62', '35.00'],
    ];

    private const RATE = '5';

    public function build(?Organization $seller): PrintSample
    {
        $profile = $seller?->complianceProfileFor(UaeSellerDetails::JURISDICTION);

        if ($seller === null || $profile === null) {
            $seller = ($seller ?? new Organization)->forceFill($seller === null ? [
                'name' => 'Sample Trading LLC',
                'country' => 'AE',
                'vat_number' => '100000000000003',
                'street' => 'Sheikh Zayed Road',
                'building_number' => '12',
                'city' => 'Dubai',
            ] : []);
            $profile = (new ComplianceProfile)->forceFill([
                'jurisdiction' => UaeSellerDetails::JURISDICTION,
                'settings' => [
                    'trn' => $seller->vat_number ?: '100000000000003',
                    'endpoint_id' => PintAe::SCHEME.':1000000000',
                    'emirate' => 'DXB',
                    'legal_registration_type' => 'TL',
                    'legal_registration_id' => 'CN-0000000',
                ],
            ]);
        }

        $lines = collect();
        $subtotal = '0.00';
        $tax = '0.00';

        foreach (self::LINES as $index => [$name, $description, $quantity, $unit, $price]) {
            $net = bcmul($quantity, $price, 2);
            $vat = bcdiv(bcmul($net, self::RATE, 4), '100', 2);
            $subtotal = bcadd($subtotal, $net, 2);
            $tax = bcadd($tax, $vat, 2);

            $lines->push((new InvoiceLine)->forceFill([
                'id' => 'sample-'.($index + 1),
                'item_code' => 'ITEM-'.(1001 + $index),
                'item_name' => $name,
                'description' => $description,
                'quantity' => $quantity,
                'unit_code' => $unit,
                'unit_price' => $price,
                'net_amount' => $net,
                'tax_rate' => self::RATE,
                'tax_category' => 'S',
                'tax_amount' => $vat,
                'line_total' => bcadd($net, $vat, 2),
            ])->setRelation('charges', collect()));
        }

        $invoice = (new Invoice)->forceFill([
            'invoice_number' => 'SAMPLE-0001',
            'type' => 'standard',
            'document_type' => 'invoice',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => PintAe::TAX_CURRENCY,
            'payment_means_code' => '30',
            'buyer_name' => 'Sample Customer LLC',
            'buyer_vat_number' => '100000000100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi', 'country_code' => 'AE'],
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => bcadd($subtotal, $tax, 2),
            'jurisdiction_data' => [
                'transaction_flags' => PintAe::NO_FLAGS,
                'buyer_endpoint' => PintAe::SCHEME.':'.PintAe::BUYER_NOT_YET_ON_EINVOICING,
                'buyer_subdivision' => 'AUH',
            ],
            'issued_at' => now(),
            'created_at' => now(),
        ]);

        $invoice->setRelation('lines', $lines)
            ->setRelation('charges', collect())
            ->setRelation('prepayments', collect())
            ->setRelation('org', $seller)
            ->setRelation('profile', $profile);

        return new PrintSample($seller, $invoice);
    }
}
