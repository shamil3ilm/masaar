<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\FTA;

use App\Domains\Compliance\FTA\Services\FtaService;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payload sent to the UAE FTA has to carry the invoice's actual values.
 *
 * Every field below was read under a name no model has — tax_registration_number
 * for the supplier, address for the street, and a contact relation that does not
 * exist for the entire customer. Eloquent answers null for an attribute a model
 * does not have, and the ?? beside each one turned that into an empty string. So
 * the payload was well-formed and empty, and the supplier TRN the FTA requires
 * was never in it.
 *
 * Asserting values rather than structure is the point: a shape assertion passes
 * just as happily on a payload of empty strings.
 */
class FtaPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_carries_the_invoice_values(): void
    {
        $organization = Organization::create([
            'name' => 'Acme FZE',
            'country' => 'AE',
            'vat_number' => '100000000000003',
            'street' => 'Sheikh Zayed Road',
            'city' => 'Dubai',
            'building_number' => '12',
            'district' => 'Business Bay',
            'postal_code' => '00000',
        ]);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'AE-1',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => '2026-03-01',
            'supply_date' => '2026-03-15',
            'currency' => 'AED',
            'buyer_name' => 'Buyer LLC',
            'buyer_vat_number' => '100000000000011',
            'buyer_address' => [
                'street' => 'Al Wasl Road',
                'city' => 'Dubai',
                'country_code' => 'AE',
            ],
            'subtotal' => '100.00',
            'tax_amount' => '5.00',
            'total' => '105.00',
        ]));

        $payload = app(FtaService::class)->buildInvoiceData($invoice, $organization);

        // The supplier TRN is mandatory; an empty one is rejected outright.
        $this->assertSame('100000000000003', $payload->supplierTrn);
        $this->assertSame('Sheikh Zayed Road', $payload->supplierStreet);
        $this->assertSame('Dubai', $payload->supplierCity);

        $this->assertSame('Buyer LLC', $payload->customerName);
        $this->assertSame('100000000000011', $payload->customerTrn);
        $this->assertSame('Al Wasl Road', $payload->customerStreet);
        $this->assertSame('Dubai', $payload->customerCity);
        $this->assertSame('AE', $payload->customerCountry);

        // supply_date is the column; there is no due_date.
        $this->assertSame('2026-03-15', $payload->dueDate);
    }

    /**
     * Without a supply date the invoice date stands in, rather than the payload
     * carrying a null the FTA would reject.
     */
    public function test_due_date_falls_back(): void
    {
        $organization = Organization::create([
            'name' => 'Acme FZE', 'country' => 'AE', 'vat_number' => '100000000000003',
        ]);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'AE-2',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => '2026-03-01',
            'currency' => 'AED',
            'buyer_name' => 'Buyer LLC',
            'subtotal' => '100.00',
            'tax_amount' => '5.00',
            'total' => '105.00',
        ]));

        $payload = app(FtaService::class)->buildInvoiceData($invoice, $organization);

        $this->assertSame('2026-03-01', $payload->dueDate);
    }
}
