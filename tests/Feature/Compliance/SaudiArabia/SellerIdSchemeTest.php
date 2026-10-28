<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\SellerIdentification;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Every seller identification scheme BR-KSA-08 allows - CRN, MOM, MLS, 700,
 * SAG, OTH (XML Implementation Standard v1.2 p48-49; the SDK schematron's
 * allowed-seller) - chosen on the company page, checked in its own shape,
 * and written as the schemeID of BT-29.
 */
class SellerIdSchemeTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function schemes(): array
    {
        return [
            'commercial registration' => ['CRN', '1010101010'],
            'momrah licence' => ['MOM', 'MOM4410'],
            'mhrsd licence' => ['MLS', 'L77120'],
            'seven hundred number' => ['700', '7001094523'],
            'misa licence' => ['SAG', 'SAG1029384'],
            'another id' => ['OTH', 'X1029384'],
        ];
    }

    #[DataProvider('schemes')]
    public function test_each_scheme_is_saved_and_written(string $scheme, string $value): void
    {
        $this->company(['seller_id_scheme' => $scheme, 'cr_number' => $value])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('portal.company.edit'));

        $acme = $this->acme->fresh();
        $this->assertSame($scheme, SellerIdentification::scheme($acme));
        $this->assertTrue($acme->hasCompleteZatcaProfile());

        $xml = $this->issued($acme);
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $path = new \DOMXPath($dom);
        $path->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $path->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $ids = $path->query('//cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID');

        $this->assertSame(1, $ids->length, 'BR-KSA-08: the seller identification exists once');
        $this->assertSame([$scheme, $value], [$ids->item(0)->getAttribute('schemeID'), $ids->item(0)->textContent]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformed(): array
    {
        return [
            'short crn' => ['CRN', '10101010'],
            '700 not starting with 7' => ['700', '6001094523'],
            'licence with a dash' => ['MOM', 'MOM-4410'],
            'placeholder digits' => ['OTH', '12345678'],
            'not a seller scheme' => ['NAT', '1010101010'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_each_scheme_checks_its_shape(string $scheme, string $value): void
    {
        $this->company(['seller_id_scheme' => $scheme, 'cr_number' => $value])->assertSessionHasErrors('cr_number');

        $this->assertNull($this->acme->fresh()->cr_number);
    }

    public function test_the_page_offers_every_scheme(): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $page = $this->asRequest(fn () => $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id])->get('/portal/company'))->assertOk();

        foreach (SellerIdentification::SCHEMES as $scheme) {
            $page->assertSee('value="'.$scheme.'"', false);
        }
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function company(array $overrides): TestResponse
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        return $this->asRequest(fn () => $this->actingAs($user)
            ->withSession(['portal_org_id' => $this->acme->id, '_token' => 'scheme-token'])
            ->from('/portal/company')
            ->post('/portal/company', [
                '_token' => 'scheme-token',
                'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي', 'vat_number' => '300000000000003',
                'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
                ...$overrides,
            ]));
    }

    private function issued(Organization $acme): string
    {
        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);

        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $acme->id, 'invoice_number' => 'SCH-1', 'type' => 'simplified', 'document_type' => 'invoice',
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]);
        $invoice->lines()->create([
            'description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        return (string) app(Submitter::class)->generate($invoice->fresh(['lines']), $acme)['signed_xml'];
    }
}
