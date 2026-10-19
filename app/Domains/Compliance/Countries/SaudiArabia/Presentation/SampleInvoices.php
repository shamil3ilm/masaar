<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Engine\DTOs\PrintSample;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;

/**
 * The tax invoice an invoice template's preview draws: a standard one, which
 * prints every column and both parties' details, in riyals at 15%.
 *
 * Built in memory and never saved. The seller is the organization's own
 * when there is one; the platform's preview gets a made-up company. The
 * number, the hash and the QR code all say "sample", so a printed preview
 * is never mistaken for an invoice: the QR holds those words, not ZATCA's
 * TLV, and nothing a phone reads from it passes for one.
 */
final class SampleInvoices
{
    /** description, Arabic description, quantity, unit, net unit price */
    private const LINES = [
        ['Consulting hour', 'ساعة استشارية', '2', 'HUR', '250.00'],
        ['Office chair', 'كرسي مكتب', '3', 'C62', '180.00'],
        ['Delivery within Riyadh', 'خدمة التوصيل داخل مدينة الرياض', '1', 'C62', '35.00'],
    ];

    private const RATE = '15';

    public function build(?Organization $seller): PrintSample
    {
        $seller ??= (new Organization)->forceFill([
            'name' => 'Sample Trading Company',
            'name_ar' => 'شركة النموذج للتجارة',
            'country' => 'SA',
            'vat_number' => '399999999900003',
            'cr_number' => '1010010000',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12211',
        ]);

        $lines = collect();
        $subtotal = '0.00';
        $tax = '0.00';

        foreach (self::LINES as $index => [$description, $arabic, $quantity, $unit, $price]) {
            $net = bcmul($quantity, $price, 2);
            $vat = bcdiv(bcmul($net, self::RATE, 4), '100', 2);
            $subtotal = bcadd($subtotal, $net, 2);
            $tax = bcadd($tax, $vat, 2);

            $line = (new InvoiceLine)->forceFill([
                'id' => 'sample-'.($index + 1),
                'description' => $description,
                'description_ar' => $arabic,
                'quantity' => $quantity,
                'unit_code' => $unit,
                'unit_price' => $price,
                'net_amount' => $net,
                'tax_rate' => self::RATE,
                'tax_category' => 'S',
                'tax_amount' => $vat,
                'line_total' => bcadd($net, $vat, 2),
            ]);
            $lines->push($line->setRelation('charges', collect()));
        }

        $invoice = (new Invoice)->forceFill([
            'invoice_number' => 'SAMPLE-0001',
            'type' => 'standard',
            'document_type' => 'invoice',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Sample Customer Company',
            'buyer_name_ar' => 'شركة العميل النموذجي',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Prince Sultan Road', 'building_number' => '7788', 'district' => 'Al Rawdah', 'city' => 'Jeddah', 'postal_code' => '23435', 'country_code' => 'SA'],
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => bcadd($subtotal, $tax, 2),
            'icv' => 1,
            'hash' => 'SAMPLE',
            'qr_code' => __('Sample - not a tax invoice'),
            'issued_at' => now(),
            'created_at' => now(),
        ]);

        $invoice->setRelation('lines', $lines)->setRelation('charges', collect())->setRelation('prepayments', collect());

        return new PrintSample($seller, $invoice);
    }
}
