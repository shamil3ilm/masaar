<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Business scenarios as a caller sends them, judged by ZATCA's validator.
 *
 * ZatcaConformanceTest writes most of its invoices straight into the table,
 * which proves the document is right for an invoice that is right. These go
 * the whole way an integrator does: POST /api/invoices, the Saudi rules, the
 * drafter, then the signed document - so a field the rules drop, or store
 * and never write, shows up here as the document ZATCA sees.
 *
 * Set ZATCA_SDK_PATH to run these; they skip without it.
 */
class ScenarioConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private Organization $organization;

    private bool $signedByAuthority = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '399999999900003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'cr_number' => '1010101010',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $authority = $this->authorityCredentials($this->sdk()->path());
        $this->signedByAuthority = $authority !== null;
        $credentials = $authority ?? $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']
        );
    }

    /**
     * KSA-2's transaction types, each in the shape ZATCA's own sample for it
     * has, with the type name that sample carries.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function transactionTypes(): iterable
    {
        yield 'export of goods' => [self::export('VATEX-SA-32'), '0100100'];
        yield 'export of services' => [self::export('VATEX-SA-33'), '0100100'];
        yield 'third party' => [self::standard(['is_third_party' => true]), '0110000'];
        yield 'nominal' => [self::simplified(['is_nominal' => true]), '0201000'];
        yield 'summary' => [self::standard(['is_summary' => true]), '0100010'];
        yield 'self billed' => [self::standard(['is_self_billed' => true]), '0100001'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('transactionTypes')]
    public function test_transaction_types_validate(array $payload, string $typeName): void
    {
        $xml = $this->signedFrom($payload);

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="'.$typeName.'">', $xml);
        $this->assertAccepted($xml);
    }

    /**
     * A month's sales summarised on one simplified invoice: BR-KSA-72 wants
     * the period it covers, and the period sent through the API is the one
     * the document states.
     */
    public function test_a_simplified_summary_states_its_period(): void
    {
        $start = now()->startOfMonth()->toDateString();
        $end = now()->toDateString();

        $xml = $this->signedFrom(self::simplified([
            'is_summary' => true,
            'buyer_name' => 'Beta Industries',
            'supply_date' => $start,
            'supply_end_date' => $end,
        ]));

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200010">', $xml);
        $this->assertMatchesRegularExpression("#<cbc:ActualDeliveryDate[^>]*>{$start}</cbc:ActualDeliveryDate>#", $xml);
        $this->assertMatchesRegularExpression("#<cbc:LatestDeliveryDate[^>]*>{$end}</cbc:LatestDeliveryDate>#", $xml);
        $this->assertAccepted($xml);
    }

    /**
     * The buyer's purchase order and contract, where UBL puts them - around
     * the billing reference, which a note carries too. The buyer's own
     * reference is kept off the document: ZATCA's data dictionary has no
     * BT-10.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function referenced(): iterable
    {
        $references = ['order_reference' => 'PO-2026-0042', 'contract_reference' => 'CT-2026-07', 'buyer_reference' => 'AP-77'];

        yield 'invoice' => [self::standard($references)];
        yield 'credit note' => [self::standard([
            ...$references,
            'document_type' => 'credit_note',
            'billing_ref' => 'INV-ORIGINAL-1',
            'adjustment_reason' => 'Correction of quantity',
        ])];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('referenced')]
    public function test_buyer_references_validate(array $payload): void
    {
        $xml = $this->signedFrom($payload);

        $this->assertMatchesRegularExpression('#<cac:OrderReference[^>]*>\s*<cbc:ID[^>]*>PO-2026-0042</cbc:ID>#', $xml);
        $this->assertMatchesRegularExpression('#<cac:ContractDocumentReference[^>]*>\s*<cbc:ID[^>]*>CT-2026-07</cbc:ID>#', $xml);
        $this->assertStringNotContainsString('BuyerReference', $xml);
        $this->assertAccepted($xml);
    }

    /**
     * Payment terms (KSA-22) where ZATCA's Schematron reads them, on an
     * invoice and on a note that states its reason (KSA-10) beside them;
     * the due date is kept on the invoice and stays off the document.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function payable(): iterable
    {
        $terms = ['payment_terms' => '30 days net', 'due_date' => now()->addDays(30)->toDateString(), 'payment_means_code' => '30'];

        yield 'invoice' => [self::standard($terms)];
        yield 'credit note' => [self::standard([
            ...$terms,
            'document_type' => 'credit_note',
            'billing_ref' => 'INV-ORIGINAL-1',
            'adjustment_reason' => 'Correction of quantity',
        ])];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('payable')]
    public function test_payment_terms_validate(array $payload): void
    {
        $xml = $this->signedFrom($payload);

        $this->assertMatchesRegularExpression('#<cbc:PaymentNote[^>]*>30 days net</cbc:PaymentNote>#', $xml);
        $this->assertStringNotContainsString('DueDate', $xml);
        $this->assertAccepted($xml);
    }

    /**
     * Each payment means the portal offers, as ZATCA reads it.
     *
     * @return iterable<string, array{string}>
     */
    public static function paymentMeans(): iterable
    {
        foreach (['10', '30', '42', '48', '1'] as $code) {
            yield "code {$code}" => [$code];
        }
    }

    #[DataProvider('paymentMeans')]
    public function test_payment_means_validate(string $code): void
    {
        $xml = $this->signedFrom(self::simplified(['payment_means_code' => $code]));

        $this->assertMatchesRegularExpression("#<cbc:PaymentMeansCode[^>]*>{$code}</cbc:PaymentMeansCode>#", $xml);
        $this->assertAccepted($xml);
    }

    /**
     * Discounts and charges, on lines and on the whole invoice, each shape
     * ZATCA has a rule about: an amount, a percentage of a base, a charge at
     * a category no line has, mixed categories under a document discount,
     * and the same with prices that include VAT.
     *
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function charged(): iterable
    {
        $delivery = ['reason' => 'Delivery', 'reason_code' => 'DL', 'amount' => '25.00'];
        $zero = ['tax_category' => 'Z', 'tax_rate' => 0, 'exempt_code' => 'VATEX-SA-32', 'exempt_reason' => 'Export of goods'];

        yield 'delivery on a simplified invoice' => [self::simplified([
            'lines' => [['description' => 'Groceries', 'quantity' => 3, 'unit_price' => '12.49']],
            'charges' => [$delivery],
        ]), 1];
        yield 'service charge percentage on a standard invoice' => [self::standard([
            'lines' => [
                ['description' => 'Dinner', 'quantity' => 4, 'unit_price' => '87.35'],
                ['description' => 'Drinks', 'quantity' => 7, 'unit_price' => '13.99'],
            ],
            'charges' => [['reason' => 'Service charge', 'reason_code' => 'ADR', 'percent' => '12.5']],
        ]), 1];
        yield 'line discount and line charges' => [self::standard([
            'lines' => [
                ['description' => 'Sofa', 'quantity' => 1, 'unit_price' => '2499.99', 'discount_amount' => '250.00', 'charges' => [
                    ['reason' => 'Installation', 'reason_code' => 'IAA', 'amount' => '150.00'],
                    ['reason' => 'Packing', 'reason_code' => 'PC', 'percent' => '2.5'],
                ]],
                ['description' => 'Cushion', 'quantity' => 3, 'unit_price' => '45.50', 'discount_amount' => '13.65'],
            ],
        ]), 2];
        yield 'mixed categories, a discount and two charges' => [self::standard([
            'discount_amount' => '33.33',
            'lines' => [
                ['description' => 'Consulting', 'quantity' => 2, 'unit_price' => '499.95'],
                ['description' => 'Exported parts', 'quantity' => 5, 'unit_price' => '81.17', 'tax_rate' => 0, 'tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-32', 'exempt_reason' => 'Export of goods'],
            ],
            'charges' => [$delivery, ['reason' => 'Freight abroad', 'reason_code' => 'FC', 'amount' => '60.00', ...$zero]],
        ]), 2];
        yield 'a charge at a category no line has' => [self::standard([
            'charges' => [['reason' => 'International freight', 'reason_code' => 'FC', 'amount' => '120.00', 'tax_category' => 'Z', 'tax_rate' => 0, 'exempt_code' => 'VATEX-SA-34-1', 'exempt_reason' => 'International transport of goods']],
        ]), 1];
        yield 'prices with VAT in them' => [self::standard([
            'prices_include_vat' => true,
            'discount_amount' => '10.00',
            'lines' => [
                ['description' => 'Shirt', 'quantity' => 3, 'unit_price' => '79.99', 'discount_amount' => '20.00', 'charges' => [['reason' => 'Gift wrap', 'reason_code' => 'PC', 'amount' => '5.75']]],
                ['description' => 'Belt', 'quantity' => 1, 'unit_price' => '49.90'],
            ],
            'charges' => [$delivery, ['reason' => 'Service charge', 'reason_code' => 'ADR', 'percent' => '5']],
        ]), 2];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('charged')]
    public function test_discounts_and_charges_validate(array $payload, int $charges): void
    {
        $xml = $this->signedFrom($payload);

        // Every charge stated, as a charge (ChargeIndicator true).
        $this->assertSame($charges, preg_match_all('#<cbc:ChargeIndicator[^>]*>true</cbc:ChargeIndicator>#', $xml));
        $this->assertAccepted($xml);
    }

    /** A prepayment invoice (386) for an advance, as the buyer is billed for it. */
    public function test_a_prepayment_invoice_validates(): void
    {
        $xml = $this->signedFrom(self::standard([
            'document_type' => 'prepayment',
            'lines' => [['description' => 'Advance for a laptop', 'quantity' => 1, 'unit_price' => '1000.00']],
        ]));

        $this->assertMatchesRegularExpression('#<cbc:InvoiceTypeCode[^>]*>386</cbc:InvoiceTypeCode>#', $xml);
        $this->assertAccepted($xml);
    }

    /**
     * The final invoice settling it, in the shape of ZATCA's "Advance
     * Payment adjustments" sample: the full supply and its VAT, a line per
     * prepaid category naming the prepayment invoice (KSA-26 to KSA-30) with
     * what was prepaid (KSA-31 to KSA-34), the prepaid amount (BT-113) and
     * what is left to pay. One prepayment mixes two categories.
     */
    public function test_a_final_invoice_settles_prepayments(): void
    {
        $this->requireSdk();
        $this->prepaid('ADV-1', [['description' => 'Advance', 'quantity' => 1, 'unit_price' => '1000.00']]);
        $this->prepaid('ADV-2', [
            ['description' => 'Advance', 'quantity' => 1, 'unit_price' => '200.00'],
            ['description' => 'Advance, export', 'quantity' => 1, 'unit_price' => '100.00', 'tax_rate' => 0, 'tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-32', 'exempt_reason' => 'Export of goods'],
        ]);

        $xml = $this->signedFrom(self::standard([
            'discount_amount' => '100.00',
            'lines' => [
                ['description' => 'Laptop', 'quantity' => 1, 'unit_price' => '2000.00'],
                ['description' => 'Exported parts', 'quantity' => 1, 'unit_price' => '300.00', 'tax_rate' => 0, 'tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-32', 'exempt_reason' => 'Export of goods'],
            ],
            'prepayments' => [['invoice_number' => 'ADV-1'], ['invoice_number' => 'ADV-2']],
        ]));

        // 1000 + 150, 200 + 30, 100 + 0.
        $this->assertMatchesRegularExpression('#<cbc:PrepaidAmount[^>]*>1480.00</cbc:PrepaidAmount>#', $xml);
        $this->assertSame(3, preg_match_all('#<cbc:DocumentTypeCode[^>]*>386</cbc:DocumentTypeCode>#', $xml));
        $this->assertAccepted($xml);
    }

    /**
     * An issued prepayment invoice, outside the device's chain so the final
     * invoice is the first in it, as the SDK's PIH check expects.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function prepaid(string $number, array $lines): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($user);

        $id = $this->withToken($token)->postJson('/api/invoices', [
            ...self::standard(['document_type' => 'prepayment', 'lines' => $lines]),
            'invoice_number' => $number,
            'issue_date' => now()->subDays(10)->toDateString(),
            'supply_date' => now()->subDays(10)->toDateString(),
        ])->assertCreated()->json('data.invoice.id');

        Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($id))
            ->forceFill(['status' => 'accepted', 'hash' => base64_encode(hash('sha256', $number, true)), 'issued_at' => now()->subDays(10)->setTime(12, 28, 17)])
            ->save();
    }

    /**
     * POST the payload to the API, then sign what was drafted.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signedFrom(array $payload): string
    {
        $this->requireSdk();

        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($user);

        $id = $this->withToken($token)->postJson('/api/invoices', [
            'invoice_number' => 'SCENARIO-1',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            ...$payload,
        ])->assertCreated()->json('data.invoice.id');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));
        $signed = app(Submitter::class)->generate($invoice, $this->organization)['signed_xml'];

        $this->assertNotEmpty($signed, 'the invoice was not signed');

        return $signed;
    }

    /**
     * No business rule broken and no advisory, the four signature and chain
     * checks a self-signed key cannot pass aside (see ZatcaConformanceTest).
     */
    private function assertAccepted(string $xml): void
    {
        $result = $this->validate($xml);

        $errors = $this->signedByAuthority
            ? array_values(array_diff($result['errors'], ['signatureValue: wrong signature Value']))
            : array_values(array_filter($result['errors'], static fn (string $error): bool => str_starts_with($error, 'BR-')));

        $this->assertSame([], $errors, 'ZATCA rejected the document.');
        $this->assertSame([], $result['warnings'], 'an advisory fired.');
    }

    /**
     * Goods or services to a business abroad, zero-rated as an export, as
     * ZATCA's export sample bills them.
     *
     * @return array<string, mixed>
     */
    private static function export(string $exemption): array
    {
        return self::standard([
            'is_export' => true,
            'buyer_vat_number' => null,
            'buyer_id' => 'US1234567891',
            'buyer_id_scheme' => 'OTH',
            'buyer_address' => [
                'street' => 'Canadian Street',
                'building_number' => '12',
                'city' => 'Houston',
                'postal_code' => '77001',
                'country_code' => 'US',
            ],
            'lines' => [[
                'description' => 'Printing equipment',
                'quantity' => 1,
                'unit_price' => '15000.00',
                'exempt_code' => $exemption,
                'exempt_reason' => $exemption === 'VATEX-SA-32' ? 'Export of goods' : 'Export of services',
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function standard(array $overrides = []): array
    {
        return [
            'type' => 'standard',
            'buyer_name' => 'Beta Industries',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => [
                'street' => 'Olaya Street',
                'building_number' => '4321',
                'district' => 'Al Murooj',
                'city' => 'Riyadh',
                'postal_code' => '11564',
                'country_code' => 'SA',
            ],
            'lines' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => '1000.00']],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function simplified(array $overrides = []): array
    {
        return [
            'type' => 'simplified',
            'lines' => [['description' => 'Gift', 'quantity' => 1, 'unit_price' => '300.00']],
            ...$overrides,
        ];
    }
}
