<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InsuranceSplits;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A sale an insurer pays for, the whole way an integrator sends it - POST
 * /api/invoices/splits, the Saudi rules, the invoice drafted and issued -
 * judged by ZATCA's validator, as ZATCA's General VAT Guideline Healthcare
 * (January 2023) has it billed:
 *
 *   to the patient, the recipient (§5.1 p21): a simplified invoice stating
 *   the amounts for the account of the insurer and of the patient (§5.2
 *   p24, Example 10 p23), a citizen's healthcare zero-rated on it with their
 *   national ID (§5.3.3 p26, BR-KSA-49);
 *   to the insurer, when it contracted for the care (Example 11 p23): a tax
 *   invoice to it, and when it does not accept all of it, the credit note on
 *   it (§5.3.2 p25, Example 12 p26) and, for care not covered, the
 *   patient's invoice.
 *
 * Set ZATCA_SDK_PATH to run these; they skip without it.
 */
class InsuranceSplitConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private const CITIZEN = [
        'buyer_name' => 'Fahad Al Harbi',
        'buyer_name_ar' => 'فهد الحربي',
        'buyer_id' => '1047392816',
        'buyer_id_scheme' => 'NAT',
    ];

    private Organization $organization;

    private Customer $insurer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Al Shifa Clinic',
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
        $credentials = $authority ?? $this->selfSignedCredentials();

        app(CredentialStore::class)->put($this->organization->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret',
        ]);

        $this->insurer = new Customer([
            'org_id' => $this->organization->id, 'name' => 'Gulf Health Insurance', 'name_ar' => 'الخليج للتأمين الصحي',
            'vat_number' => '399999999800003', 'is_insurer' => true, 'street' => 'Olaya Street', 'building_number' => '4321',
            'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA',
        ]);
        Customer::withoutTenantScope(fn () => $this->insurer->save());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function sales(): iterable
    {
        // A pharmacy's basket billed to the patient: a medicine zero-rated
        // as VATEX-SA-35 (§6.1 p28-29) and a standard-rated item, 20% from
        // the patient, prices with VAT.
        yield 'to the patient, medicines, by percentage' => [[
            'prices_include_vat' => true,
            'lines' => [
                ['description' => 'Insulin pen', 'description_ar' => 'قلم إنسولين', 'quantity' => 3, 'unit_price' => '96.35', 'exempt_code' => 'VATEX-SA-35'],
                ['description' => 'Glucose strips', 'description_ar' => 'شرائط قياس السكر', 'quantity' => 1, 'unit_price' => '57.50'],
            ],
            'split' => ['method' => 'percent', 'percent' => 20],
        ], '0200000'];
        // A fixed deductible.
        yield 'to the patient, a fixed amount' => [[
            'lines' => [
                ['description' => 'Antibiotic', 'description_ar' => 'مضاد حيوي', 'quantity' => 3, 'unit_price' => '10.00', 'exempt_code' => 'VATEX-SA-35'],
                ['description' => 'Dressing', 'description_ar' => 'ضماد', 'quantity' => 1, 'unit_price' => '70.00'],
            ],
            'split' => ['method' => 'amount', 'amount' => '27.50'],
        ], '0200000'];
        // A citizen's private healthcare, most of it paid by the insurer,
        // zero-rated on their own invoice with their national ID.
        yield 'to a citizen, healthcare zero-rated' => [[
            ...self::CITIZEN,
            'lines' => [
                ['description' => 'Specialist consultation', 'description_ar' => 'استشارة أخصائي', 'quantity' => 1, 'unit_price' => '450.00', 'exempt_code' => 'VATEX-SA-HEA'],
                ['description' => 'Arm sling', 'description_ar' => 'حمالة ذراع', 'quantity' => 2, 'unit_price' => '35.00'],
            ],
            'split' => ['method' => 'percent', 'percent' => 15],
        ], '0200000'];
        yield 'to the insurer, the recipient' => [self::toInsurer(), '0100000'];
    }

    /**
     * @param  array<string, mixed>  $sale
     */
    #[DataProvider('sales')]
    public function test_the_invoice_validates(array $sale, string $typeName): void
    {
        $split = $this->issuedSplit($sale);
        $xml = (string) $split->sale()->signed_xml;

        $this->assertAccepted($xml, 'the invoice');
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="'.$typeName.'">388</cbc:InvoiceTypeCode>', $xml);

        if ($split->recipient === InsuranceSplit::PATIENT) {
            $this->assertStringContainsString('For the account of the insurer Gulf Health Insurance: '.number_format((float) $split->insurer_share, 2, '.', ''), $xml);
            $this->assertStringContainsString('Claim CLM-2026-0042.', $xml);
        } else {
            $this->assertMatchesRegularExpression('#<cac:OrderReference[^>]*>\s*<cbc:ID[^>]*>CLM-2026-0042</cbc:ID>#', $xml);
            $this->assertStringContainsString('Patient: Fahad Al Harbi.', $xml);
        }
    }

    /**
     * The insurer, the recipient, accepts less: a credit note on its tax
     * invoice, and for care it does not cover, the patient's simplified
     * invoice with the citizen's healthcare zero-rated again.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function decisions(): iterable
    {
        yield 'not covered at all' => ['0', InsuranceSplit::NOT_COVERED];
        yield 'partly not covered' => ['199.99', InsuranceSplit::NOT_COVERED];
        yield 'its agreed price' => ['390.00', InsuranceSplit::PRICE];
    }

    #[DataProvider('decisions')]
    public function test_a_decision_validates(string $approved, string $decision): void
    {
        $split = $this->issuedSplit(self::toInsurer());

        app(InsuranceSplits::class)->settle($split, $this->organization, $approved, $decision, null, new IssueOptions(file: false));
        $split->refresh();

        $credit = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($split->credit_note_id));
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">381</cbc:InvoiceTypeCode>', (string) $credit->signed_xml);
        $this->assertAccepted((string) $credit->signed_xml, 'the credit note on the insurer\'s invoice');

        if ($decision === InsuranceSplit::NOT_COVERED) {
            $rebill = Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($split->rebill_invoice_id));
            $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">388</cbc:InvoiceTypeCode>', (string) $rebill->signed_xml);
            $this->assertStringContainsString('VATEX-SA-HEA', (string) $rebill->signed_xml);
            $this->assertStringContainsString('credit note '.$credit->invoice_number, (string) $rebill->signed_xml);
            $this->assertAccepted((string) $rebill->signed_xml, 'the patient\'s invoice');
        } else {
            $this->assertNull($split->rebill_invoice_id);
        }

        $this->assertChained();
    }

    /**
     * A citizen's consultation and a dressing, the insurer the recipient.
     *
     * @return array<string, mixed>
     */
    private static function toInsurer(): array
    {
        return [
            ...self::CITIZEN,
            'lines' => [
                ['description' => 'Specialist consultation', 'description_ar' => 'استشارة أخصائي', 'quantity' => 1, 'unit_price' => '450.00', 'exempt_code' => 'VATEX-SA-HEA'],
                ['description' => 'Dressing', 'description_ar' => 'ضماد', 'quantity' => 3, 'unit_price' => '10.00'],
            ],
            'split' => ['recipient' => 'insurer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $sale
     */
    private function issuedSplit(array $sale): InsuranceSplit
    {
        $this->requireSdk();

        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'admin'])->fromUser($user);

        $response = $this->withToken($token)->postJson('/api/invoices/splits', [
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            ...$sale,
            'split' => [...$sale['split'], 'insurer_customer_id' => $this->insurer->id, 'claim_reference' => 'CLM-2026-0042', 'policy_number' => 'POL-77', 'member_id' => 'MEM-1234'],
        ]);
        $this->assertSame(201, $response->status(), (string) $response->getContent());

        $split = InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()->findOrFail($response->json('data.split.id')));
        $outcome = app(InsuranceSplits::class)->issue($split, $this->organization, new IssueOptions(file: false))['invoice'];

        $this->assertTrue((bool) $outcome?->issued, 'the invoice was not issued');

        // A decision's credit note waits for the insurer's invoice to be
        // accepted by ZATCA (VatPeriodTracker); here it is, as it would be.
        Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $this->organization->id)->where('status', 'issued')->update(['status' => 'accepted']));

        return $split->fresh('invoices');
    }

    /**
     * ZATCA's content checks passed, no business rule broken and no
     * advisory. The SDK's PIH stage compares against a file of its own, so
     * a document after the first in the chain is read for its predecessor
     * instead (assertChained(), as TillConformanceTest does).
     */
    private function assertAccepted(string $xml, string $what): void
    {
        $this->assertNotSame('', $xml, "{$what} was not signed");

        $result = $this->validate($xml);

        $this->assertTrue($this->sdk()->contentPassed($result), "ZATCA rejected {$what}:\n".implode("\n", $result['errors']));
        $this->assertSame([], array_values(array_filter($result['errors'], static fn (string $error): bool => str_starts_with($error, 'BR-'))), "ZATCA rejected {$what}.");
        $this->assertSame([], $result['warnings'], "an advisory fired on {$what}.");
    }

    /** Every document of the organization's chain names the one before it. */
    private function assertChained(): void
    {
        $previous = null;

        foreach (Invoice::withoutTenantScope(fn () => Invoice::query()->whereNotNull('icv')->orderBy('icv')->get()) as $invoice) {
            if ($previous !== null) {
                $this->assertSame($previous->hash, $invoice->previous_invoice_hash, "{$invoice->invoice_number} does not name {$previous->invoice_number}");
                $this->assertStringContainsString((string) $previous->hash, (string) $invoice->signed_xml);
            }

            $previous = $invoice;
        }
    }
}
