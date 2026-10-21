<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthorityAnswer;
use Tests\Fixtures\RecordedOriginals;
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
    use RecordedOriginals;
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
        // International transport, zero-rated by VAT Implementing Regulations
        // Article 34 (VATEX-SA-34-1), and an export mixing an exported good
        // and a standard-rated service in the Kingdom: ZATCA ties no category
        // or code to an export. The freight under its own reason beside the
        // good is a second reason in category Z, refused (see
        // test_two_reasons_in_one_category_refused).
        yield 'export with international transport' => [self::export('VATEX-SA-34-1'), '0100100'];
        yield 'mixed export' => [self::export('VATEX-SA-32', [
            ['description' => 'Handling in Dammam', 'description_ar' => 'مناولة في الدمام', 'quantity' => 1, 'unit_price' => '100.00'],
        ]), '0100100'];
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
     * A business-to-government transaction: KSA-2 position 9 in ZATCA's SDK
     * (BR-KSA-06, nine characters), with the contract BR-KSA-92 requires on
     * the invoice and on its notes.
     */
    public function test_a_government_invoice_validates(): void
    {
        $xml = $this->signedFrom(self::standard(['is_government' => true, 'contract_reference' => 'MOF-2026-118']));

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="010000001">388</cbc:InvoiceTypeCode>', $xml);
        $this->assertMatchesRegularExpression('#<cac:ContractDocumentReference[^>]*>\s*<cbc:ID[^>]*>MOF-2026-118</cbc:ID>#', $xml);
        $this->assertAccepted($xml);
    }

    public function test_a_government_credit_note_validates(): void
    {
        $xml = $this->signedFrom(self::standard([
            'is_government' => true,
            'contract_reference' => 'MOF-2026-118',
            'document_type' => 'credit_note',
            'billing_ref' => 'INV-B2G-1',
            'adjustment_reason' => 'إرجاع البضاعة / Returned goods',
        ]));

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="010000001">381</cbc:InvoiceTypeCode>', $xml);
        $this->assertAccepted($xml);
    }

    /**
     * Without its contract a government invoice is refused here, with
     * BR-KSA-92, and built anyway ZATCA's validator refuses it the same.
     */
    public function test_a_government_invoice_needs_its_contract(): void
    {
        $this->requireSdk();
        $token = $this->token();
        $dates = ['issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString()];

        $this->withToken($token)->postJson('/api/invoices', ['invoice_number' => 'B2G-REFUSED', ...$dates, ...self::standard(['is_government' => true])])
            ->assertStatus(422)
            ->assertJsonPath('error.details.contract_reference.0', 'A government (B2G) invoice names the contract it falls under (BR-KSA-92).');

        $id = $this->withToken($token)->postJson('/api/invoices', ['invoice_number' => 'B2G-FORCED', ...$dates, ...self::standard()])
            ->assertCreated()->json('data.invoice.id');
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));
        $invoice->forceFill(['is_government' => true])->saveQuietly();

        $xml = app(DocumentBuilder::class)->generateComplianceData($invoice->fresh('lines'), $this->organization)['xml'];

        $this->assertContains('BR-KSA-92', array_map(fn (string $error): string => explode(':', $error)[0], $this->validate($xml)['errors']));
    }

    /**
     * What ZATCA refuses on an export, refused here with its rule, and the
     * same document built anyway and put to ZATCA's validator: BR-KSA-07, an
     * export cannot be self-billed.
     */
    public function test_a_self_billed_export_is_refused(): void
    {
        $this->requireSdk();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/invoices', [
            'invoice_number' => 'SCENARIO-REFUSED', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            ...self::export('VATEX-SA-32'), 'is_self_billed' => true,
        ])->assertStatus(422)->assertJsonPath('error.details.is_self_billed.0', 'An export invoice cannot be self-billed (BR-KSA-07).');

        $id = $this->withToken($token)->postJson('/api/invoices', [
            'invoice_number' => 'SCENARIO-FORCED', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            ...self::export('VATEX-SA-32'),
        ])->assertCreated()->json('data.invoice.id');
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));
        $invoice->forceFill(['is_self_billed' => true])->saveQuietly();

        $xml = app(DocumentBuilder::class)->generateComplianceData($invoice->fresh('lines'), $this->organization)['xml'];

        $this->assertContains('BR-KSA-07', array_map(fn (string $error): string => explode(':', $error)[0], $this->validate($xml)['errors']));
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
            'buyer_name_ar' => 'شركة بيتا للصناعات',
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
            'adjustment_reason' => 'تصحيح الكمية / Correction of quantity',
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
        $terms = ['payment_terms' => 'صافي ٣٠ يوماً / 30 days net', 'due_date' => now()->addDays(30)->toDateString(), 'payment_means_code' => '30'];

        yield 'invoice' => [self::standard($terms)];
        yield 'credit note' => [self::standard([
            ...$terms,
            'document_type' => 'credit_note',
            'billing_ref' => 'INV-ORIGINAL-1',
            'adjustment_reason' => 'تصحيح الكمية / Correction of quantity',
        ])];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('payable')]
    public function test_payment_terms_validate(array $payload): void
    {
        $xml = $this->signedFrom($payload);

        $this->assertMatchesRegularExpression('#<cbc:PaymentNote[^>]*>صافي ٣٠ يوماً / 30 days net</cbc:PaymentNote>#', $xml);
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
            'lines' => [['description' => 'Groceries', 'description_ar' => 'مواد غذائية', 'quantity' => 3, 'unit_price' => '12.49']],
            'charges' => [$delivery],
        ]), 1];
        yield 'service charge percentage on a standard invoice' => [self::standard([
            'lines' => [
                ['description' => 'Dinner', 'description_ar' => 'عشاء', 'quantity' => 4, 'unit_price' => '87.35'],
                ['description' => 'Drinks', 'description_ar' => 'مشروبات', 'quantity' => 7, 'unit_price' => '13.99'],
            ],
            'charges' => [['reason' => 'Service charge', 'reason_code' => 'ADR', 'percent' => '12.5']],
        ]), 1];
        yield 'line discount and line charges' => [self::standard([
            'lines' => [
                ['description' => 'Sofa', 'description_ar' => 'أريكة', 'quantity' => 1, 'unit_price' => '2499.99', 'discount_amount' => '250.00', 'charges' => [
                    ['reason' => 'Installation', 'reason_code' => 'IAA', 'amount' => '150.00'],
                    ['reason' => 'Packing', 'reason_code' => 'PC', 'percent' => '2.5'],
                ]],
                ['description' => 'Cushion', 'description_ar' => 'وسادة', 'quantity' => 3, 'unit_price' => '45.50', 'discount_amount' => '13.65'],
            ],
        ]), 2];
        yield 'mixed categories, a discount and two charges' => [self::standard([
            'discount_amount' => '33.33',
            'lines' => [
                ['description' => 'Consulting', 'description_ar' => 'خدمات استشارية', 'quantity' => 2, 'unit_price' => '499.95'],
                ['description' => 'Exported parts', 'description_ar' => 'قطع مصدّرة', 'quantity' => 5, 'unit_price' => '81.17', 'tax_rate' => 0, 'tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-32', 'exempt_reason' => 'Export of goods'],
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
                ['description' => 'Shirt', 'description_ar' => 'قميص', 'quantity' => 3, 'unit_price' => '79.99', 'discount_amount' => '20.00', 'charges' => [['reason' => 'Gift wrap', 'reason_code' => 'PC', 'amount' => '5.75']]],
                ['description' => 'Belt', 'description_ar' => 'حزام', 'quantity' => 1, 'unit_price' => '49.90'],
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

    /**
     * Sector invoices, each with its exemption reason from §11.2.4, the
     * official text filled in by the rules: a pharmacy's mixed basket -
     * zero-rated medicines (VATEX-SA-35) and standard-rated cosmetics under
     * one discount, prices with VAT in them as at a till - and a citizen's
     * private healthcare and education (VATEX-SA-HEA, VATEX-SA-EDU), which
     * name the buyer and identify them by national ID (BR-KSA-25,
     * BR-KSA-49).
     *
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function sectors(): iterable
    {
        $citizen = ['buyer_name' => 'Fahad Al Harbi', 'buyer_name_ar' => 'فهد الحربي', 'buyer_id' => '1047392816', 'buyer_id_scheme' => 'NAT'];

        yield 'mixed pharmacy basket' => [self::simplified([
            'prices_include_vat' => true,
            'discount_amount' => '5.00',
            'lines' => [
                ['description' => 'Paracetamol 500mg', 'description_ar' => 'باراسيتامول ٥٠٠ ملغ', 'quantity' => 3, 'unit_price' => '12.75', 'tax_category' => 'Z', 'tax_rate' => 0, 'exempt_code' => 'VATEX-SA-35'],
                ['description' => 'Insulin pen', 'description_ar' => 'قلم إنسولين', 'quantity' => 1, 'unit_price' => '189.90', 'tax_category' => 'Z', 'tax_rate' => 0, 'exempt_code' => 'VATEX-SA-35'],
                ['description' => 'Sunscreen SPF50', 'description_ar' => 'واقي شمس', 'quantity' => 2, 'unit_price' => '64.50', 'tax_category' => 'S', 'tax_rate' => 15],
            ],
        ]), ['VATEX-SA-35']];
        yield 'citizen healthcare' => [self::simplified([...$citizen, 'lines' => [
            ['description' => 'Specialist consultation', 'description_ar' => 'استشارة طبيب مختص', 'quantity' => 1, 'unit_price' => '450.00', 'exempt_code' => 'VATEX-SA-HEA'],
        ]]), ['VATEX-SA-HEA']];
        yield 'citizen education' => [self::simplified([...$citizen, 'lines' => [
            ['description' => 'Tuition, first term', 'description_ar' => 'الرسوم الدراسية للفصل الأول', 'quantity' => 1, 'unit_price' => '12500.00', 'exempt_code' => 'VATEX-SA-EDU'],
            ['description' => 'School uniform', 'description_ar' => 'الزي المدرسي', 'quantity' => 2, 'unit_price' => '150.00'],
        ]]), ['VATEX-SA-EDU']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $reasons
     */
    #[DataProvider('sectors')]
    public function test_sector_invoices_validate(array $payload, array $reasons): void
    {
        $xml = $this->signedFrom($payload);

        foreach ($reasons as $reason) {
            $this->assertMatchesRegularExpression("#<cbc:TaxExemptionReasonCode[^>]*>{$reason}</cbc:TaxExemptionReasonCode>#", $xml);
        }

        if (array_intersect($reasons, ['VATEX-SA-HEA', 'VATEX-SA-EDU']) !== []) {
            $this->assertMatchesRegularExpression('#<cbc:ID[^>]*schemeID="NAT">1047392816</cbc:ID>#', $xml);
        }

        $this->assertAccepted($xml);
    }

    /**
     * A citizen's healthcare without the national ID is refused here with
     * BR-KSA-49, and built anyway, ZATCA's validator refuses it the same.
     */
    public function test_citizen_healthcare_needs_national_id(): void
    {
        $this->requireSdk();
        $token = $this->token();
        $dates = ['issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString()];
        $line = ['description' => 'Consultation', 'description_ar' => 'استشارة', 'quantity' => 1, 'unit_price' => '300.00', 'exempt_code' => 'VATEX-SA-HEA'];

        $this->withToken($token)->postJson('/api/invoices', ['invoice_number' => 'HEA-REFUSED', ...$dates, ...self::simplified(['buyer_name' => 'Fahad Al Harbi', 'lines' => [$line]])])
            ->assertStatus(422)
            ->assertJsonPath('error.details.buyer_id_scheme.0', 'VATEX-SA-HEA is zero-rated only for a citizen: identify the buyer by national ID (BR-KSA-49).');

        $id = $this->withToken($token)->postJson('/api/invoices', [
            'invoice_number' => 'HEA-FORCED', ...$dates,
            ...self::simplified(['buyer_name' => 'Fahad Al Harbi', 'buyer_id' => '1047392816', 'buyer_id_scheme' => 'NAT', 'lines' => [$line]]),
        ])->assertCreated()->json('data.invoice.id');
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));
        $invoice->forceFill(['buyer_id_scheme' => 'IQA', 'buyer_id' => '2047392816'])->saveQuietly();

        $xml = app(DocumentBuilder::class)->generateComplianceData($invoice->fresh('lines'), $this->organization)['xml'];

        $this->assertContains('BR-KSA-49', array_map(fn (string $error): string => explode(':', $error)[0], $this->validate($xml)['errors']));
    }

    /**
     * An exported good and its international freight are both zero-rated,
     * under two reasons (VATEX-SA-32, VATEX-SA-34-1). The XML Implementation
     * Standard gives category Z one breakdown (§8.4) and states the reason
     * only there, so the second would be lost: the rules refuse it. The one
     * other way to say it - a breakdown per reason - is what ZATCA's
     * validator answers with BR-Z-08, each breakdown's base being checked
     * against every zero-rated line.
     */
    public function test_two_reasons_in_one_category_refused(): void
    {
        $this->requireSdk();
        $freight = ['description' => 'Freight to Houston', 'description_ar' => 'شحن إلى هيوستن', 'quantity' => 1, 'unit_price' => '900.00', 'tax_category' => 'Z', 'tax_rate' => 0, 'exempt_code' => 'VATEX-SA-34-1'];

        $this->withToken($this->token())->postJson('/api/invoices', [
            'invoice_number' => 'EXPORT-REFUSED', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            ...self::export('VATEX-SA-32', [$freight]),
        ])->assertStatus(422)->assertJsonPath('error.details', fn (array $details): bool => str_starts_with((string) ($details['lines.1.exempt_code'][0] ?? ''), 'Tax category Z takes one exemption reason per invoice'));

        // The same two lines under one reason, then the breakdown split by reason.
        $xml = $this->signedFrom(self::export('VATEX-SA-32', [[...$freight, 'exempt_code' => 'VATEX-SA-32']]));
        $document = new DOMDocument;
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $good = $xpath->query("/*/cac:TaxTotal/cac:TaxSubtotal[cac:TaxCategory/cbc:ID='Z']")->item(0);
        $transport = $good->parentNode->insertBefore($good->cloneNode(true), $good->nextSibling);
        $xpath->query('cbc:TaxableAmount', $good)->item(0)->nodeValue = '15000.00';
        $xpath->query('cbc:TaxableAmount', $transport)->item(0)->nodeValue = '900.00';
        $xpath->query('cac:TaxCategory/cbc:TaxExemptionReasonCode', $transport)->item(0)->nodeValue = 'VATEX-SA-34-1';
        $xpath->query('cac:TaxCategory/cbc:TaxExemptionReason', $transport)->item(0)->nodeValue = 'International transport of goods';

        $this->assertContains('BR-Z-08', array_map(fn (string $warning): string => explode(':', $warning)[0], $this->validate((string) $document->saveXML())['warnings']));
    }

    /**
     * Out of scope (VATEX-SA-OOS) has no text in §11.2.4 of the XML
     * Implementation Standard: the seller words it, and the breakdown states
     * one wording (BT-120). Two wordings would lose one and are refused; the
     * same wording on two lines is one breakdown, which ZATCA's validator
     * takes (BR-KSA-24, BR-KSA-83).
     */
    public function test_one_wording_per_seller_reason(): void
    {
        $this->requireSdk();
        $consulting = ['description' => 'Consulting', 'description_ar' => 'خدمات استشارية', 'quantity' => 1, 'unit_price' => '1000.00'];
        $fees = fn (string $words, string $price): array => [
            'description' => 'Government fees', 'description_ar' => 'رسوم حكومية', 'quantity' => 1, 'unit_price' => $price,
            'tax_category' => 'O', 'tax_rate' => 0, 'exempt_code' => 'VATEX-SA-OOS', 'exempt_reason' => $words,
        ];

        $this->withToken($this->token())->postJson('/api/invoices', [
            'invoice_number' => 'OOS-REFUSED', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            ...self::standard(['lines' => [$consulting, $fees('Visa fees paid for the buyer', '250.00'), $fees('Court fees paid for the buyer', '120.00')]]),
        ])->assertStatus(422)->assertJsonPath('error.details', fn (array $details): bool => str_starts_with((string) ($details['lines.2.exempt_code'][0] ?? ''), 'VATEX-SA-OOS is stated with one wording per invoice'));

        $xml = $this->signedFrom(self::standard(['lines' => [$consulting, $fees('Government fees paid for the buyer', '250.00'), $fees('Government fees paid for the buyer', '120.00')]]));

        $this->assertSame(1, preg_match_all('#<cbc:TaxExemptionReasonCode[^>]*>VATEX-SA-OOS</cbc:TaxExemptionReasonCode>#', $xml));
        $this->assertAccepted($xml);
    }

    /** A prepayment invoice (386) for an advance, as the buyer is billed for it. */
    public function test_a_prepayment_invoice_validates(): void
    {
        $xml = $this->signedFrom(self::standard([
            'document_type' => 'prepayment',
            'lines' => [['description' => 'Advance for a laptop', 'description_ar' => 'دفعة مقدمة لحاسوب محمول', 'quantity' => 1, 'unit_price' => '1000.00']],
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
        $this->prepaid('ADV-1', [['description' => 'Advance', 'description_ar' => 'دفعة مقدمة', 'quantity' => 1, 'unit_price' => '1000.00']]);
        $this->prepaid('ADV-2', [
            ['description' => 'Advance', 'description_ar' => 'دفعة مقدمة', 'quantity' => 1, 'unit_price' => '200.00'],
            ['description' => 'Advance, export', 'description_ar' => 'دفعة مقدمة، تصدير', 'quantity' => 1, 'unit_price' => '100.00', 'tax_rate' => 0, 'tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-32', 'exempt_reason' => 'Export of goods'],
        ]);

        $xml = $this->signedFrom(self::standard([
            'discount_amount' => '100.00',
            'lines' => [
                ['description' => 'Laptop', 'description_ar' => 'حاسوب محمول', 'quantity' => 1, 'unit_price' => '2000.00'],
                ['description' => 'Exported parts', 'description_ar' => 'قطع مصدّرة', 'quantity' => 1, 'unit_price' => '300.00', 'tax_rate' => 0, 'tax_category' => 'Z', 'exempt_code' => 'VATEX-SA-32', 'exempt_reason' => 'Export of goods'],
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

        AuthorityAnswer::record(Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($id)), 'accepted', ['hash' => base64_encode(hash('sha256', $number, true)), 'issued_at' => now()->subDays(10)->setTime(12, 28, 17)]);
    }

    /**
     * POST the payload to the API, then sign what was drafted.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signedFrom(array $payload): string
    {
        $this->requireSdk();

        if (isset($payload['billing_ref'])) {
            $this->recordedOriginal($this->organization, (string) $payload['billing_ref'], (string) ($payload['type'] ?? 'standard'));
        }

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

    private function token(): string
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);

        return JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($user);
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
    private static function export(string $exemption, array $more = []): array
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
                'description_ar' => 'معدات طباعة',
                'quantity' => 1,
                'unit_price' => '15000.00',
                'exempt_code' => $exemption,
                'exempt_reason' => match ($exemption) {
                    'VATEX-SA-32' => 'Export of goods',
                    'VATEX-SA-33' => 'Export of services',
                    default => 'International transport of goods',
                },
            ], ...$more],
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
            'lines' => [['description' => 'Consulting', 'description_ar' => 'خدمات استشارية', 'quantity' => 1, 'unit_price' => '1000.00']],
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
            'lines' => [['description' => 'Gift', 'description_ar' => 'هدية', 'quantity' => 1, 'unit_price' => '300.00']],
            ...$overrides,
        ];
    }
}
