<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Receiving\ReceivedReader;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentStatus;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;

/**
 * /portal/received: invoices other suppliers sent a UAE organization, each
 * shown by what its XML says - as escaped text, never as markup - and only
 * to the organization it was sent to.
 */
class ReceivedInvoicesTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        $this->gulf = $this->uaeOrganization();
        $this->member = $this->memberOf($this->gulf);
    }

    public function test_list_shows_parsed_fields(): void
    {
        $document = $this->received($this->gulf, $this->ubl());

        $this->open('/portal/received')
            ->assertOk()
            ->assertSee('SUP-2026-0042')
            ->assertSee('Desert Supplies LLC')
            ->assertSee('0235:1000000001')
            ->assertSee('1050.00 AED')
            ->assertSee(route('portal.received.show', $document->id), false);

        $this->open("/portal/received/{$document->id}")
            ->assertOk()
            ->assertSee('SUP-2026-0042')
            ->assertSee('2026-10-01')
            ->assertSee('2026-10-31')
            ->assertSee('100999888700003')
            ->assertSee('50.00 AED')
            ->assertSee(route('portal.received.xml', $document->id), false);
    }

    public function test_other_organizations_documents_are_hidden(): void
    {
        $rival = $this->uaeOrganization(['endpoint_id' => '0235:1000000009']);
        $theirs = $this->received($rival, $this->ubl(number: 'RIVAL-7'));

        $this->open('/portal/received')->assertOk()->assertDontSee('RIVAL-7')->assertSee('No invoices received yet.');
        $this->open("/portal/received/{$theirs->id}")->assertNotFound();
        $this->open("/portal/received/{$theirs->id}/xml")->assertNotFound();
    }

    public function test_sent_document_is_not_received(): void
    {
        $sent = $this->received($this->gulf, $this->ubl(number: 'OUR-OWN-1'), PeppolDocument::DIRECTION_OUT);

        $this->open('/portal/received')->assertDontSee('OUR-OWN-1');
        $this->open("/portal/received/{$sent->id}")->assertNotFound();
    }

    /** A supplier's value is text on our page, whatever it contains. */
    public function test_hostile_values_render_as_text(): void
    {
        $hostile = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $document = $this->received($this->gulf, $this->ubl(number: htmlspecialchars($hostile, ENT_XML1), seller: htmlspecialchars($hostile, ENT_XML1)));

        foreach (['/portal/received', "/portal/received/{$document->id}"] as $uri) {
            $this->open($uri)
                ->assertOk()
                ->assertDontSee($hostile, false)
                ->assertDontSee('<img src=x', false)
                ->assertSee(e($hostile), false);
        }
    }

    /** No DOCTYPE, so no entity is declared, expanded or fetched. */
    public function test_doctype_document_is_not_read(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE Invoice [<!ENTITY secret SYSTEM "file:///etc/passwd"><!ENTITY big "EXPANDED">]>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<cbc:ID>&big;&secret;</cbc:ID></Invoice>';

        $summary = app(ReceivedReader::class)->summarise($xml);
        $this->assertSame(['', ''], [$summary->number, $summary->kind]);

        $document = $this->received($this->gulf, $xml);
        $this->open("/portal/received/{$document->id}")->assertOk()->assertDontSee('EXPANDED')->assertSee('No number');
    }

    public function test_xml_downloads_as_received(): void
    {
        $document = $this->received($this->gulf, $this->ubl());

        $response = $this->open("/portal/received/{$document->id}/xml")->assertOk();

        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame($document->xml, $response->getContent());
    }

    /**
     * Switching the UAE off stops new issuing, not a UAE organization's view
     * of what it received - and the portal says the country is off.
     */
    public function test_switched_off_still_shows_received(): void
    {
        $document = $this->received($this->gulf, $this->ubl());
        config(['peppol.countries.AE.enabled' => false]);

        $this->open('/portal/received')->assertOk()->assertSee('E-invoicing in your country is switched off on this platform.');
        $this->open("/portal/received/{$document->id}")->assertOk();
        $this->open("/portal/received/{$document->id}/xml")->assertOk();
    }

    public function test_navigation_links_for_uae_only(): void
    {
        $this->open('/portal/invoices')->assertSee(route('portal.received.index'), false);

        // Not a screen a UAE organization onboards on.
        $this->asRequest(fn () => $this->actingAs($this->memberOf($this->gulf, 'admin'))
            ->withSession(['portal_org_id' => $this->gulf->id])
            ->get('/portal/invoices')
            ->assertDontSee(route('portal.zatca.show'), false));
    }

    private function open(string $uri): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->member)
            ->withSession(['portal_org_id' => $this->gulf->id])
            ->get($uri));
    }

    private function received(Organization $organization, string $xml, string $direction = PeppolDocument::DIRECTION_IN): PeppolDocument
    {
        return PeppolDocument::acrossTenants()->create([
            'org_id' => $organization->id,
            'direction' => $direction,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            'xml' => $xml,
            'asp_message_id' => 'msg-'.uniqid(),
            'status' => $direction === PeppolDocument::DIRECTION_IN ? DocumentStatus::RECEIVED : DocumentStatus::READY,
            'sender_endpoint' => '0235:1000000001',
            'receiver_endpoint' => '0235:1001234567',
        ]);
    }

    /** A received invoice, as a supplier's provider delivers one. */
    private function ubl(string $number = 'SUP-2026-0042', string $seller = 'Desert Supplies LLC'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<cbc:CustomizationID>urn:peppol:pint:billing-1@ae-1</cbc:CustomizationID>'
            .'<cbc:ID>'.$number.'</cbc:ID>'
            .'<cbc:IssueDate>2026-10-01</cbc:IssueDate>'
            .'<cbc:DueDate>2026-10-31</cbc:DueDate>'
            .'<cbc:DocumentCurrencyCode>AED</cbc:DocumentCurrencyCode>'
            .'<cac:AccountingSupplierParty><cac:Party>'
            .'<cac:PartyTaxScheme><cbc:CompanyID>100999888700003</cbc:CompanyID></cac:PartyTaxScheme>'
            .'<cac:PartyLegalEntity><cbc:RegistrationName>'.$seller.'</cbc:RegistrationName></cac:PartyLegalEntity>'
            .'</cac:Party></cac:AccountingSupplierParty>'
            .'<cac:TaxTotal><cbc:TaxAmount currencyID="AED">50.00</cbc:TaxAmount></cac:TaxTotal>'
            .'<cac:LegalMonetaryTotal><cbc:PayableAmount currencyID="AED">1050.00</cbc:PayableAmount></cac:LegalMonetaryTotal>'
            .'</Invoice>';
    }

    private function memberOf(Organization $organization, string $role = 'member'): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
