<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * What the buyer's own paperwork calls the sale - its purchase order, the
 * contract it falls under and the reference it asked to be quoted - on a
 * Saudi invoice.
 *
 * A buyer's accounts payable matches an invoice by these, and a government
 * buyer pays none without them. ZATCA's document carries the purchase order
 * (BT-13) and the contract (BT-12); it has no buyer reference, so that one
 * is kept and shown, not written into the XML.
 */
class DocumentReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCES = ['order_reference' => 'PO-2026-0042', 'contract_reference' => 'CT-2026-07', 'buyer_reference' => 'AP-77'];

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '399999999900003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_the_references_are_kept(): void
    {
        $this->api(self::REFERENCES)->assertCreated();

        $this->assertSame(self::REFERENCES, $this->invoice()->only(array_keys(self::REFERENCES)));
    }

    /**
     * UBL's order: the purchase order before the billing reference, the
     * contract after it, both before the counter and the chain's hash.
     */
    public function test_the_document_states_them_in_order(): void
    {
        $this->api([...self::REFERENCES, 'billing_ref' => 'INV-1', 'document_type' => 'credit_note', 'adjustment_reason' => 'تصحيح الكمية / Correction of quantity'])->assertCreated();

        $xml = app(DocumentBuilder::class)->generateComplianceData($this->invoice(), $this->acme)['xml'];
        $order = array_map(fn (string $element): int|false => strpos($xml, $element), [
            '<cbc:TaxCurrencyCode',
            '<cac:OrderReference',
            '<cac:BillingReference',
            '<cac:ContractDocumentReference',
            '<cac:AdditionalDocumentReference',
        ]);

        $this->assertNotContains(false, $order, $xml);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'the references are out of UBL order');
        $this->assertMatchesRegularExpression('#<cac:OrderReference[^>]*>\s*<cbc:ID[^>]*>PO-2026-0042</cbc:ID>#', $xml);
        $this->assertStringNotContainsString('AP-77', $xml);
    }

    /**
     * None sent, none written: an invoice without them is the document it
     * always was.
     */
    public function test_none_sent_writes_none(): void
    {
        $this->api([])->assertCreated();

        $xml = app(DocumentBuilder::class)->generateComplianceData($this->invoice(), $this->acme)['xml'];

        $this->assertStringNotContainsString('OrderReference', $xml);
        $this->assertStringNotContainsString('ContractDocumentReference', $xml);
    }

    /** BR-KSA-F-06-C3: 127 characters at most. */
    public function test_a_long_purchase_order_is_refused(): void
    {
        $this->api(['order_reference' => str_repeat('P', 128)])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('order_reference', 'error.details');
    }

    public function test_the_invoice_page_shows_them(): void
    {
        $this->api(self::REFERENCES)->assertCreated();

        $this->asRequest(fn () => $this->actingAs($this->admin)
            ->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.invoices.show', $this->invoice()->id))
            ->assertOk()
            ->assertSee('Purchase order: PO-2026-0042')
            ->assertSee('Contract: CT-2026-07')
            ->assertSee('Buyer reference: AP-77'));
    }

    private function invoice(): Invoice
    {
        return Invoice::acrossTenants()->with('lines')->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function api(array $overrides): TestResponse
    {
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);

        return $this->withToken($token)->postJson('/api/invoices', [
            'invoice_number' => 'INV-2',
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ...$overrides,
        ]);
    }
}
