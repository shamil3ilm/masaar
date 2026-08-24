<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\Fixtures\ZatcaSdk;
use Tests\TestCase;

/**
 * Documents this platform produces, judged by ZATCA rather than by us.
 *
 * Every other test in this suite asserts that the code does what the code
 * intends. That is worth having and it is not conformance: it cannot tell you
 * that BT-23 must read "reporting:1.0" on a standard invoice, because nothing
 * in this repository knew. ZATCA's validator knows — it rejected the document
 * as BR-KSA-EN16931-01 — and this runs it.
 *
 * Four validators: the UBL 2.1 schema, the CEN EN16931 rules, ZATCA's own
 * Schematron, and the PIH chain check.
 *
 * Set ZATCA_SDK_PATH to run these. They skip without it, because the SDK is a
 * licensed download that cannot live in the repository.
 */
class ZatcaConformanceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;
    use ZatcaSdk;

    private Organization $organization;

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
            // BR-KSA-08 wants a seller identification with a scheme ID, and a
            // commercial registration number is the first of the six it
            // accepts. Without one the document still passes, with an advisory.
            'cr_number' => '1010101010',
            'compliance_profile' => ['zatca_onboarded' => true],
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
     * The schema is the floor. A document that fails it is not an invoice, and
     * no amount of correct business logic above it matters.
     */
    public function test_standard_invoice_matches_the_schema(): void
    {
        $result = $this->validate($this->signed('standard'));

        $this->assertSame(
            'PASSED',
            $result['stages']['XSD'] ?? 'ABSENT',
            "UBL 2.1 schema rejected the document:\n".implode("\n", $result['errors'])
        );
    }

    public function test_standard_invoice_passes_zatca_rules(): void
    {
        $result = $this->validate($this->signed('standard'));

        $this->assertSame(
            'PASSED',
            $result['stages']['KSA'] ?? 'ABSENT',
            "ZATCA rules rejected the document:\n".implode("\n", $result['errors'])
        );
    }

    public function test_standard_invoice_passes_en16931(): void
    {
        $result = $this->validate($this->signed('standard'));

        $this->assertSame(
            'PASSED',
            $result['stages']['EN'] ?? 'ABSENT',
            "EN 16931 rejected the document:\n".implode("\n", $result['errors'])
        );
    }

    public function test_simplified_invoice_passes_zatca_rules(): void
    {
        $result = $this->validate($this->signed('simplified'));

        $this->assertSame(
            'PASSED',
            $result['stages']['KSA'] ?? 'ABSENT',
            "ZATCA rules rejected the simplified document:\n".implode("\n", $result['errors'])
        );
    }

    /**
     * The six documents ZATCA's compliance check requires.
     *
     * Onboarding is not granted on a tax invoice alone: a taxpayer must submit
     * a standard and a simplified invoice, and a credit and a debit note of
     * each, before the production CSID is issued. Two of the six were checked
     * here; the notes had never been put in front of the authority at all.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        foreach (['standard', 'simplified'] as $type) {
            foreach (['invoice', 'credit_note', 'debit_note'] as $document) {
                yield "{$type} {$document}" => [$type, $document];
            }
        }
    }

    #[DataProvider('documents')]
    public function test_every_compliance_document_validates(string $type, string $document): void
    {
        $result = $this->validate($this->signed($type, $document));

        $this->assertSame(
            [],
            $this->businessRules($result['errors']),
            "ZATCA rejected the {$type} {$document}."
        );

        $this->assertSame([], $result['warnings'], "an advisory fired on the {$type} {$document}.");
    }

    /**
     * The rule violations a document can fix, separated from the ones only a
     * real CSID can.
     *
     * Four of the SDK's checks — the certificate, the QR that embeds it, the
     * signature over both, and the PIH chain it compares against its own
     * configured file — cannot pass with the self-signed key these tests
     * generate. They are not findings about the document, and asserting on
     * them would mean this suite could only ever run for someone holding a
     * production certificate.
     *
     * What is left is the business rules, which are about what the invoice
     * says. Those must be clean.
     *
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function businessRules(array $errors): array
    {
        return array_values(array_filter(
            $errors,
            static fn (string $error): bool => str_starts_with($error, 'BR-')
        ));
    }

    /**
     * Advisories are not failures, which is exactly why they need a test.
     *
     * BR-KSA-51 sat here reporting that every line's amount-with-VAT was zero,
     * and the document cleared anyway. A rule ZATCA is willing to overlook is
     * still a rule about what the invoice says.
     */
    public function test_no_advisories(): void
    {
        $result = $this->validate($this->signed('standard'));

        $this->assertSame([], $result['warnings'], 'a ZATCA advisory rule fired');
    }

    /**
     * The harness itself, checked against a document known to be good.
     *
     * If ZATCA's own sample fails here, the finding is about this test — a
     * stale config path, a missing schema, the wrong version — and not about
     * the platform. Without this, a broken harness reads as a broken invoice.
     */
    public function test_the_authority_own_sample_passes(): void
    {
        $sdk = $this->requireSdk();

        $sample = $sdk.'/Data/Samples/Standard/Invoice/Standard_Invoice.xml';

        if (! is_file($sample)) {
            $this->markTestSkipped('The SDK carries no standard invoice sample.');
        }

        $result = $this->validate((string) file_get_contents($sample));

        $this->assertSame(
            'PASSED',
            $result['global'],
            "The harness is wrong, not the platform:\n".implode("\n", $result['errors'])
        );
    }

    private function signed(string $type, string $document = 'invoice'): string
    {
        $isNote = $document !== 'invoice';

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => strtoupper($type.'-'.$document).'-1',
            'type' => $type,
            'document_type' => $document,
            // A credit or debit note corrects an earlier invoice, and BR-KSA-56
            // wants to know which one.
            'billing_ref' => $isNote ? 'INV-ORIGINAL-1' : null,
            'adjustment_reason' => $isNote ? 'Correction of quantity' : null,
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Beta Industries',
            'buyer_vat_number' => $type === 'standard' ? '399999999800003' : null,
            // BR-KSA-10 and the BR-KSA-F-06 family read the buyer address.
            // A standard invoice without one still validates, and reports an
            // advisory per missing field — which is noise this test would
            // otherwise have to carry as expected.
            'buyer_address' => $type === 'standard' ? [
                'street' => 'Olaya Street',
                'building_number' => '4321',
                'district' => 'Al Murooj',
                'city' => 'Riyadh',
                'postal_code' => '11564',
                'country_code' => 'SA',
            ] : null,
            'subtotal' => '1000.00',
            'tax_amount' => '150.00',
            'total' => '1150.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => '1.000',
            'unit_price' => '1000.00',
            'tax_rate' => '15.00',
            'tax_category' => 'S',
            'tax_amount' => '150.00',
            'line_total' => '1150.00',
        ]);

        $result = app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization);

        $this->assertNotEmpty($result['signed_xml'], 'the invoice was not signed');

        return $result['signed_xml'];
    }
}
