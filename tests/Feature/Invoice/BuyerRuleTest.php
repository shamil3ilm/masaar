<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\InvoiceRules;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * What the authority requires of the buyer, and no more.
 *
 * The request required a buyer name on every invoice and a buyer VAT number
 * on every standard one. ZATCA asks for less, and the rules were read from the
 * schematron in its own SDK:
 *
 *   BR-KSA-25  a simplified invoice names its buyer only when a line is
 *              exempt as private education or healthcare supplied to a Saudi
 *              citizen. A walk-in sale names nobody.
 *   BR-KSA-81  a standard invoice identifies its buyer by VAT number or,
 *              failing that, by another identifier (BT-46). A business buyer
 *              that is not VAT-registered can still be invoiced.
 *
 * Being stricter than the authority is not caution here. It made the cashier
 * invent a name for every cash sale, which then went to ZATCA as that buyer's
 * registered name, and it refused outright every business buyer below the VAT
 * registration threshold.
 */
class BuyerRuleTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $user = User::factory()->create();
        $user->organizations()->attach($acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $acme->id, 'role' => 'admin'])->fromUser($user);
    }

    /**
     * A cash sale to someone who gave no name.
     */
    public function test_a_walk_in_sale_is_unnamed(): void
    {
        $this->create(['buyer_name' => null])->assertCreated();
    }

    /**
     * And with the field left out, which is how most clients say it.
     *
     * The drafter read buyer_name without a default. An explicit null got
     * through; an absent key was a 500 on every walk-in sale an ERP sent
     * without one - the case the optional name was made optional for.
     */
    public function test_walk_in_needs_no_buyer_field(): void
    {
        $this->withToken($this->token)->postJson('/api/invoices', [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();
    }

    /**
     * Except where the exemption depends on who the buyer is.
     */
    public function test_a_healthcare_exemption_needs_a_name(): void
    {
        $this->create([
            'buyer_name' => null,
            'lines' => [[
                'description' => 'Consultation',
                'description_ar' => 'استشارة',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'tax_category' => 'Z',
                'exempt_code' => 'VATEX-SA-HEA',
                'exempt_reason' => 'Private healthcare to a citizen',
            ]],
        ])->assertStatus(422)->assertJsonPath('error.details.buyer_name.0',
            'The buyer name is required on a standard invoice, and on a simplified one exempt as private education or healthcare (BR-KSA-25).');
    }

    /**
     * A standard invoice still names its buyer.
     */
    public function test_a_standard_invoice_needs_a_name(): void
    {
        $this->create($this->standard(['buyer_name' => null]))->assertStatus(422)
            ->assertJsonValidationErrorFor('buyer_name', 'error.details');
    }

    /**
     * A business buyer that is not VAT-registered, identified another way.
     *
     * And the identifier is kept. This asserted the 201 and stopped, and
     * InvoiceDrafter never wrote either field: the request was accepted on
     * the strength of an identifier that was then discarded, so the invoice
     * reached ZATCA with neither BT-48 nor BT-46 and failed BR-KSA-81 there.
     */
    public function test_a_buyer_id_replaces_vat(): void
    {
        $this->create($this->standard([
            'buyer_vat_number' => null,
            'buyer_id' => '1010101010',
            'buyer_id_scheme' => 'CRN',
        ]))->assertCreated();

        $this->assertDatabaseHas('invoices', ['buyer_id' => '1010101010', 'buyer_id_scheme' => 'CRN']);
    }

    /**
     * But one of the two it must have.
     */
    public function test_a_standard_invoice_identifies_its_buyer(): void
    {
        $this->create($this->standard(['buyer_vat_number' => null]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.buyer_vat_number.0',
                'A standard invoice needs the buyer VAT number or another buyer identifier.');
    }

    /**
     * BR-KSA-63: a Saudi buyer's national address is whole on a standard
     * invoice, its district and postal code included.
     */
    public function test_saudi_buyer_needs_district_and_postcode(): void
    {
        $address = $this->standard([])['buyer_address'];

        $details = $this->create($this->standard(['buyer_address' => [...$address, 'district' => null, 'postal_code' => null]]))
            ->assertStatus(422)
            ->json('error.details');

        $this->assertSame("A Saudi buyer's district is required on a standard invoice (BR-KSA-63).", $details['buyer_address.district'][0] ?? null);
        $this->assertSame("A Saudi buyer's postal code is required on a standard invoice (BR-KSA-63).", $details['buyer_address.postal_code'][0] ?? null);
    }

    /** A buyer abroad keeps its own address form. */
    public function test_foreign_buyer_needs_no_district(): void
    {
        $address = $this->standard([])['buyer_address'];

        $this->create($this->standard(['buyer_vat_number' => null, 'buyer_id' => 'GB123456789', 'buyer_id_scheme' => 'OTH', 'buyer_address' => [...$address, 'district' => null, 'postal_code' => null, 'country_code' => 'GB']]))
            ->assertCreated();
    }

    /**
     * Said in the field's own words: "buyer VAT number (BT-48)" named a
     * term of the EN 16931 model nobody filling the form knows.
     */
    public function test_messages_name_no_business_terms(): void
    {
        foreach (app(InvoiceRules::class)->messages() as $key => $message) {
            $this->assertDoesNotMatchRegularExpression('/\bBT-\d+/', $message, $key);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function standard(array $overrides): array
    {
        return [
            'type' => 'standard',
            'buyer_name' => 'Beta Industries',
            'buyer_name_ar' => 'شركة بيتا للصناعات',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => [
                'street' => 'Olaya Street',
                'building_number' => '4321',
                'district' => 'Al Murooj',
                'city' => 'Riyadh',
                'postal_code' => '11564',
                'country_code' => 'SA',
            ],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/invoices', [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer',
            'buyer_name_ar' => 'المشتري',
            'lines' => [[
                'description' => 'Item',
                'description_ar' => 'صنف',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
            ...$overrides,
        ]);
    }
}
