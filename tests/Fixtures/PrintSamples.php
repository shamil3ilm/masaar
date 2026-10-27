<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\TlvEncoder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Closure;

/**
 * Invoices that test a printed layout the way a till does: bilingual, a long
 * list of lines, long Arabic names, and a QR code as long as a Phase 2 one.
 *
 * A one-line invoice with a twelve-character QR payload fits on any paper;
 * what clips a column, wraps an amount or makes a QR unreadable at 58 mm is
 * the real thing, so that is what these are.
 */
trait PrintSamples
{
    private int $sampleCounter = 0;

    private function sampleOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Gulf Horizon Trading and Contracting Company',
            'name_ar' => 'شركة أفق الخليج للتجارة والمقاولات والخدمات اللوجستية المحدودة',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'additional_street' => 'Al Olaya Street',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
    }

    /**
     * An issued invoice: 'simplified' (a till's receipt) or 'standard',
     * issued by a branch when one is given.
     *
     * What a test needs different is given before it is issued - $state over
     * the issued columns, $prepare on the draft and its lines - or it is left
     * a draft: an issued invoice is not rewritten (the database refuses it on
     * MySQL, migration 1808).
     *
     * @param  array<string, mixed>  $state
     * @param  (Closure(Invoice): void)|null  $prepare
     */
    private function sampleInvoice(Organization $organization, string $type, int $lines = 14, ?string $branchId = null, array $state = [], ?Closure $prepare = null, bool $issued = true): Invoice
    {
        $number = 'INV-2026-'.str_pad((string) ++$this->sampleCounter, 6, '0', STR_PAD_LEFT);
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $organization->id,
            'branch_id' => $branchId,
            'invoice_number' => $number,
            'type' => $type,
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ]));

        $items = [
            ['Arabic coffee, Saudi roast, 500 g', 'قهوة عربية محمصة على الطريقة السعودية ٥٠٠ غرام', '3', '42.50'],
            ['Dates, Sukkari, premium box', 'تمر سكري فاخر - علبة هدايا مغلفة', '2', '89.00'],
            ['Rose water', 'ماء ورد طائفي أصلي', '6', '12.75'],
            ['Saffron threads, 5 g', 'زعفران إيراني شعيرات - ٥ غرامات', '1', '65.00'],
            ['Cardamom, green, 250 g', 'هيل أخضر مختار', '4', '28.00'],
            ['Delivery within Riyadh', 'خدمة التوصيل داخل مدينة الرياض', '1', '25.00'],
        ];
        $subtotal = 0.0;
        $tax = 0.0;

        for ($i = 0; $i < $lines; $i++) {
            [$english, $arabic, $quantity, $price] = $items[$i % count($items)];
            $net = round((float) $quantity * (float) $price, 2);
            $vat = round($net * 0.15, 2);
            $subtotal += $net;
            $tax += $vat;

            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => $english,
                'description_ar' => $arabic,
                'quantity' => $quantity,
                'unit_code' => 'C62',
                'unit_price' => $price,
                'tax_rate' => '15',
                'tax_amount' => number_format($vat, 2, '.', ''),
                'line_total' => number_format($net + $vat, 2, '.', ''),
            ]);
        }

        if ($prepare !== null) {
            $prepare($invoice);
        }

        $invoice->forceFill([
            'status' => $issued ? 'issued' : 'draft',
            'buyer_name' => $type === 'standard' ? 'Al Noor Hospitality Group' : 'Walk-in customer',
            'buyer_name_ar' => $type === 'standard' ? 'مجموعة النور للضيافة والفنادق والمنتجعات السياحية' : null,
            'buyer_vat_number' => $type === 'standard' ? '310122393500003' : null,
            'buyer_address' => $type === 'standard'
                ? ['street' => 'Prince Sultan Road', 'building_number' => '7788', 'district' => 'Al Rawdah', 'city' => 'Jeddah', 'postal_code' => '23435', 'country_code' => 'SA']
                : null,
            'supply_date' => now()->toDateString(),
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'tax_amount' => number_format($tax, 2, '.', ''),
            'total' => number_format($subtotal + $tax, 2, '.', ''),
            'icv' => 1000 + $this->sampleCounter,
            'hash' => base64_encode(hash('sha256', $number, true)),
            'qr_code' => $this->samplePhaseTwoQr($organization, $number, $subtotal + $tax, $tax),
            'signed_xml' => '<?xml version="1.0" encoding="UTF-8"?><Invoice>'.$number.'</Invoice>',
            'cleared_xml' => $type === 'standard' ? '<?xml version="1.0" encoding="UTF-8"?><Invoice>'.$number.' cleared</Invoice>' : null,
            ...$state,
        ])->save();

        return $invoice->fresh(['lines']);
    }

    /**
     * A QR payload of a Phase 2 invoice's length: the five readable tags,
     * then a hash, an ECDSA signature (base64, as the XML holds it), a public
     * key and a certificate signature of the real sizes. Their bytes are
     * random: a layout cares how long they are, not what they sign.
     */
    private function samplePhaseTwoQr(Organization $organization, string $number, float $total, float $vat): string
    {
        $signature = random_bytes(71);
        $publicKey = random_bytes(88);
        $certificateSignature = random_bytes(71);

        return (new TlvEncoder)->encode([
            1 => (string) $organization->name_ar,
            2 => (string) $organization->vat_number,
            3 => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            4 => number_format($total, 2, '.', ''),
            5 => number_format($vat, 2, '.', ''),
            6 => base64_encode(hash('sha256', $number, true)),
            7 => base64_encode((string) $signature),
            8 => $publicKey,
            9 => (string) $certificateSignature,
        ]);
    }
}
