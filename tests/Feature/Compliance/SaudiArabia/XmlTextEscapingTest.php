<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineFallback;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\PipelineService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Text with &, < or > reaches the signed invoice intact.
 *
 * The UBL builders passed text as createElementNS()'s third argument, which
 * DOM reads as markup: "Smith & Sons" was an unterminated entity and the
 * element was written empty - a tax invoice to a buyer with no name.
 * Ampersands are common in company names, so this is not an edge case.
 */
class XmlTextEscapingTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const SELLER = 'Al Noor Trading & Contracting <KSA>';

    private const STREET = 'King Fahd Road & Olaya Street';

    private const BUYER = 'Smith & Sons';

    private const ITEM = 'Nuts & bolts <M8>';

    public function test_ampersands_survive_into_the_xml(): void
    {
        $organization = Organization::create([
            'name' => self::SELLER,
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => self::STREET,
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        // Signed, so the document read back is the one a buyer receives.
        $credentials = $this->selfSignedCredentials(0x1234567890);
        app(CredentialStore::class)->put($organization->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'],
            'pcsid' => $credentials['certificate'],
            'secret' => 'device-secret',
        ]);

        $this->mock(Connectivity::class, fn ($mock) => $mock->shouldReceive('shouldUseOfflineMode')->andReturn(false));
        $this->mock(OfflineFallback::class, fn ($mock) => $mock->shouldNotReceive('submit'));

        $result = app(PipelineService::class)->submitInvoice([
            'invoice_number' => 'AMP-0001',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => self::BUYER,
            'auto_submit' => false,
            'lines' => [[
                'description' => self::ITEM,
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 15,
                'unit_code' => 'PCE',
            ]],
        ], $organization->id);

        $this->assertSame([], $result['errors']);

        $xml = (string) Invoice::acrossTenants()->findOrFail($result['invoice_id'])->signed_xml;
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml), 'the signed invoice is not well-formed XML');
        $xpath = new DOMXPath($document);

        $text = fn (string $path): string => trim((string) $xpath->evaluate("string({$path})"));

        $this->assertSame(self::SELLER, $text('//*[local-name()="AccountingSupplierParty"]//*[local-name()="RegistrationName"]'));
        $this->assertSame(self::STREET, $text('//*[local-name()="AccountingSupplierParty"]//*[local-name()="StreetName"]'));
        $this->assertSame(self::BUYER, $text('//*[local-name()="AccountingCustomerParty"]//*[local-name()="RegistrationName"]'));
        $this->assertSame(self::ITEM, $text('//*[local-name()="InvoiceLine"]//*[local-name()="Item"]/*[local-name()="Name"]'));
        $this->assertStringContainsString('Smith &amp; Sons', $xml);
    }
}
