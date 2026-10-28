<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Document\XmlBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\AddressData;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\SellerRules;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BR-KSA-08: the seller identification (BT-29) exists once, with one of the
 * schemes CRN, MOM, MLS, SAG, OTH or 700 - never "VAT", which the XML fell
 * back to when no commercial registration number was set.
 */
class SellerIdentificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        Http::fake();

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
    }

    public function test_no_crn_means_an_incomplete_profile(): void
    {
        $this->assertFalse(app(SellerRules::class)->complete($this->acme));

        $this->acme->update(['cr_number' => '1010101010']);

        $this->assertTrue(app(SellerRules::class)->complete($this->acme->fresh()));
    }

    public function test_onboarding_waits_for_the_crn(): void
    {
        try {
            app(OrganizationOnboarding::class)->requestCcsid($this->acme, '123456', ['common_name' => 'EGS1', 'serial_number' => '1-x|2-1|3-1']);
            $this->fail('onboarding went ahead without a seller identification');
        } catch (DomainException $e) {
            $this->assertStringContainsString('commercial registration', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_standard_invoice_waits_for_the_crn(): void
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id, 'invoice_number' => 'STD-1', 'type' => 'standard', 'document_type' => 'invoice',
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'buyer_name' => 'Beta Industries', 'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]);
        $invoice->lines()->create([
            'description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        try {
            app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);
            $this->fail('a standard invoice was issued without a seller identification');
        } catch (FatooraException $e) {
            $this->assertStringContainsString('BR-KSA-08', $e->getMessage());
        }

        $this->assertNull($invoice->fresh()->icv, 'a counter was spent on a refused invoice');
    }

    public function test_seller_scheme_is_never_vat(): void
    {
        $xml = app(XmlBuilder::class)->build(new InvoiceXmlData(
            uuid: '3cf5ee18-ee25-44ea-a444-2c37ba7f28be', invoiceNumber: 'S-1', icv: 1,
            issueDate: '2026-10-20', issueTime: '10:00:00', invoiceTypeCode: '388', invoiceSubtype: '02', currency: 'SAR',
            sellerName: 'Acme Trading', sellerVatNumber: '300000000000003',
            sellerAddress: new AddressData(street: 'King Fahd Road', buildingNumber: '1234', district: 'Al Olaya', city: 'Riyadh', postalCode: '12345'),
            buyerName: 'Cash Customer', subtotal: 100.0, taxAmount: 15.0, total: 115.0,
            lines: [['description' => 'Widget', 'quantity' => 1.0, 'unitPrice' => 100.0, 'taxRate' => 15.0, 'taxAmount' => 15.0, 'lineTotal' => 100.0, 'taxCategory' => 'S', 'unitCode' => 'PCE']],
            sellerCrNumber: null,
        ));

        $this->assertStringNotContainsString('schemeID="VAT"', $xml);
    }
}
