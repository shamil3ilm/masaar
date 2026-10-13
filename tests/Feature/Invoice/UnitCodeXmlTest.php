<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * What the authority actually receives as the unit.
 *
 * UnitCodeTest covers the lookup. This covers the document, because the
 * lookup being right is no use if the fallback that reaches the XML is
 * somewhere else - which is exactly what had happened. 'PCE' was written into
 * six files as the default, including twice inside XmlBuilder itself, so
 * correcting one of them would have left the others emitting it.
 *
 * A line with no unit code is the common case: neither the pipeline nor the
 * compliance API requires one, and most ERP payloads leave it out.
 */
class UnitCodeXmlTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $credentials = $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
        );
    }

    /**
     * A line that names no unit is filed as a piece, in the code that exists.
     */
    public function test_no_unit_files_h87(): void
    {
        $xml = $this->issue('ACME-1', null);

        $this->assertStringContainsString('unitCode="H87"', $xml);
        $this->assertStringNotContainsString('unitCode="PCE"', $xml,
            'PCE is not a UN/ECE Recommendation 20 code and ZATCA recommends against it.');
    }

    /**
     * And a line that names a code this platform used to send is filed as
     * the code that replaced it.
     */
    public function test_a_legacy_unit_is_filed_translated(): void
    {
        $xml = $this->issue('ACME-2', 'BX');

        $this->assertStringContainsString('unitCode="XBX"', $xml);
        $this->assertStringNotContainsString('unitCode="BX"', $xml);
    }

    /**
     * And the row agrees with the document that was filed.
     *
     * These are two separate defaults and only one of them was masked.
     * DocumentBuilder normalises on the way into the XML, so the document was
     * right from the moment UnitCode existed - but the line it was built from
     * still said PCE, because the column's own default said PCE. A stored
     * line that disagrees with the document the authority holds is the kind
     * of drift that is only found by reconciling, which nobody does.
     */
    public function test_the_stored_line_agrees(): void
    {
        $this->issue('ACME-3', null);

        $this->assertDatabaseHas('invoice_lines', ['unit_code' => 'H87']);
        $this->assertDatabaseMissing('invoice_lines', ['unit_code' => 'PCE']);
    }

    private function issue(string $number, ?string $unitCode): string
    {
        $invoice = Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $line = [
            'description' => 'Item',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ];

        // Omitted entirely rather than passed as null: the column is NOT NULL,
        // so a line with no unit takes the schema's default - which is the
        // path that was emitting PCE underneath every default in app/.
        if ($unitCode !== null) {
            $line['unit_code'] = $unitCode;
        }

        $invoice->lines()->create($line);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization);

        return (string) $invoice->fresh()->signed_xml;
    }
}
