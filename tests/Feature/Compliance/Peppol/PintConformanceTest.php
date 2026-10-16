<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\Services\PeppolPreparer;
use App\Domains\Invoice\Models\Invoice;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Documents this platform produces, judged by the published PINT AE rules.
 *
 * The official validation artefacts - the PINT schematron and the UAE's own,
 * compiled to XSLT and shipped in the specification's resources.zip - are
 * run with Saxon over each document, and any failed assertion flagged fatal
 * or error fails the test. Where the UBL 2.1 schemas are present they are
 * checked too, since the schematrons do not look at element order.
 *
 * Set PEPPOL_ARTEFACTS_PATH to a directory holding the billing resources
 * unzipped under billing/ and the self-billing ones under selfbilling/
 * (optionally the UBL 2.1 package under ubl21/), and PEPPOL_XSLT_JAR to a
 * jar with Saxon in it. Without both, these skip: the artefacts are a
 * download and CI has no Java.
 *
 * @see https://docs.peppol.eu/poac/ae/pint-ae/resources.zip
 * @see https://docs.peppol.eu/poac/ae/pint-ae-sb/resources.zip
 */
class PintConformanceTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private const RULES = ['PINT-UBL-validation-preprocessed.xslt', 'PINT-jurisdiction-aligned-rules.xslt'];

    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        foreach (['standard', 'foreign currency', 'zero-rated export', 'reverse charge', 'credit note', 'commercial invoice', 'commercial credit note', 'self-billed invoice', 'self-billed credit note', 'discount across categories', 'margin scheme', 'deemed supply', 'free zone'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('documents')]
    public function test_document_passes_official_rules(string $case): void
    {
        [$artefacts, $jar] = $this->requireArtefacts();

        $document = app(PeppolPreparer::class)->prepare($this->invoiceFor($case));
        $failures = $this->validate($document->xml, $artefacts, $jar);

        $this->assertSame([], $failures, "PINT AE refused the {$case}:\n".implode("\n", $failures)."\n\n".$document->xml);
    }

    /**
     * An invoice drafted and sent through /api/compliance/ae, as an
     * integrator would: the document the platform queued is the one judged.
     */
    public function test_api_invoice_passes_official_rules(): void
    {
        [$artefacts, $jar] = $this->requireArtefacts();
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();

        $organization = $this->uaeOrganization();
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);
        $api = $this->withToken(JWTAuth::claims(['org_id' => $organization->id, 'role' => 'admin'])->fromUser($user));

        $id = $api->postJson('/api/compliance/ae/invoices', [
            'issue_date' => '2026-10-10',
            'due_date' => '2026-11-09',
            'payment_means_code' => '30',
            'discount_amount' => '25.00',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'lines' => [
                ['item_name' => 'Consulting', 'description' => 'Consulting services, October', 'quantity' => 2, 'unit_code' => 'DAY', 'unit_price' => 500, 'tax_category' => 'S', 'tax_rate' => 5],
                ['item_name' => 'Medicine', 'quantity' => 3, 'unit_code' => 'H87', 'unit_price' => 33.33, 'tax_category' => 'Z'],
            ],
        ])->assertCreated()->json('data.invoice.id');
        $documentId = $api->postJson("/api/compliance/ae/invoices/{$id}/send")->assertOk()->json('data.document_id');

        $xml = PeppolDocument::query()->findOrFail($documentId)->xml;
        $failures = $this->validate($xml, $artefacts, $jar);

        $this->assertSame([], $failures, "PINT AE refused the API invoice:\n".implode("\n", $failures)."\n\n".$xml);
    }

    /**
     * The specification's own examples, through the same validator: if
     * these fail, the harness is wrong rather than the documents.
     */
    public function test_official_examples_pass(): void
    {
        [$artefacts, $jar] = $this->requireArtefacts();
        $examples = glob($artefacts.'/{billing,selfbilling}/trn-*/example/*.xml', GLOB_BRACE) ?: [];

        $this->assertNotEmpty($examples, 'No examples under '.$artefacts);

        $failures = [];

        foreach ($examples as $example) {
            foreach ($this->schematron((string) file_get_contents($example), $artefacts, $jar) as $failure) {
                $failures[] = basename($example).': '.$failure;
            }
        }

        $this->assertSame([], $failures);
    }

    private function invoiceFor(string $case): Invoice
    {
        $commercialSeller = ['trn' => null];
        $supplier = ['buyer_legal_registration' => ['type' => 'TL', 'id' => 'CN-7654321', 'authority' => 'Abu Dhabi Department of Economic Development']];
        $abroad = [
            'buyer_name' => 'Export Traders Inc',
            'buyer_vat_number' => null,
            'buyer_address' => ['street' => '5th Avenue', 'city' => 'New York', 'country_code' => 'US'],
        ];

        return match ($case) {
            'standard' => $this->uaeInvoice($this->uaeOrganization()),
            'foreign currency' => $this->uaeInvoice($this->uaeOrganization(), ['currency' => 'USD', 'exchange_rate' => '3.6725']),
            'zero-rated export' => $this->uaeInvoice(
                $this->uaeOrganization(),
                $abroad,
                [$this->uaeLine(['tax_category' => 'Z', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 1000])],
                ['transaction_flags' => '00000001', 'buyer_endpoint' => null, 'buyer_subdivision' => 'NY'],
            ),
            'reverse charge' => $this->uaeInvoice(
                $this->uaeOrganization(),
                [],
                [
                    $this->uaeLine(['description' => 'Mobile phones', 'item_name' => 'Phone X', 'quantity' => 10, 'unit_code' => 'H87', 'unit_price' => 1500, 'net_amount' => 15000, 'tax_category' => 'AE', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 15000]),
                    $this->uaeLine(),
                ],
                ['lines' => ['1' => ['goods_type' => 'DL8.48.8.2', 'item_id' => '09506000134352', 'hs_code' => '851713']]],
            ),
            'credit note' => $this->uaeInvoice($this->uaeOrganization(), ['document_type' => 'credit_note', 'billing_ref' => 'AE-INV-0001', 'due_date' => null], null, ['credit_reason' => 'DL8.61.1.D']),
            'commercial invoice' => $this->uaeInvoice(
                $this->uaeOrganization($commercialSeller),
                [],
                [
                    $this->uaeLine(['description' => 'Bare land lease', 'item_name' => 'Plot 12', 'quantity' => 1, 'unit_code' => 'C62', 'unit_price' => 20000, 'net_amount' => 20000, 'tax_category' => 'E', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 20000, 'exempt_code' => 'DL8.46.3', 'exempt_reason' => 'Bare land']),
                    $this->uaeLine(['tax_category' => 'O', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 1000]),
                ],
                array_merge(['document_category' => 'commercial_invoice'], $supplier),
            ),
            'commercial credit note' => $this->uaeInvoice(
                $this->uaeOrganization($commercialSeller),
                ['document_type' => 'credit_note', 'billing_ref' => 'AE-CI-0001', 'due_date' => null],
                [$this->uaeLine(['tax_category' => 'O', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 1000])],
                array_merge(['document_category' => 'commercial_credit_note', 'credit_reason' => 'DL8.61.1.A'], $supplier),
            ),
            'self-billed invoice' => $this->uaeInvoice($this->uaeOrganization(), ['is_self_billed' => true], null, $supplier),
            'self-billed credit note' => $this->uaeInvoice($this->uaeOrganization(), ['is_self_billed' => true, 'document_type' => 'credit_note', 'billing_ref' => 'AE-SB-0001', 'due_date' => null], null, array_merge(['credit_reason' => 'DL8.61.1.A'], $supplier)),
            'discount across categories' => $this->uaeInvoice(
                $this->uaeOrganization(),
                ['discount_amount' => '100.00'],
                [
                    $this->uaeLine(),
                    $this->uaeLine(['description' => 'Medicines', 'item_name' => 'Medicine', 'quantity' => 3, 'unit_code' => 'H87', 'unit_price' => 33.33, 'net_amount' => 99.99, 'tax_category' => 'Z', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 99.99]),
                ],
            ),
            'margin scheme' => $this->uaeInvoice(
                $this->uaeOrganization(),
                [],
                [$this->uaeLine(['description' => 'Used car', 'item_name' => 'Sedan 2019', 'quantity' => 1, 'unit_code' => 'H87', 'unit_price' => 40000, 'net_amount' => 40000, 'tax_category' => 'N', 'tax_rate' => 5, 'tax_amount' => 0, 'line_total' => 40000])],
                ['transaction_flags' => '00100000'],
            ),
            'deemed supply' => $this->uaeInvoice($this->uaeOrganization(), ['due_date' => null], null, ['transaction_flags' => '01000000', 'buyer_endpoint' => null]),
            'free zone' => $this->uaeInvoice($this->uaeOrganization(), [], null, ['transaction_flags' => '10000000', 'beneficiary_id' => '100765432100003']),
        };
    }

    /**
     * @return list<string> every fatal or error assertion, and schema error
     */
    private function validate(string $xml, string $artefacts, string $jar): array
    {
        return array_merge($this->schema($xml, $artefacts), $this->schematron($xml, $artefacts, $jar));
    }

    /** @return list<string> */
    private function schema(string $xml, string $artefacts): array
    {
        $root = str_contains(substr($xml, 0, 300), '<CreditNote') ? 'CreditNote' : 'Invoice';
        $xsd = $artefacts."/ubl21/xsd/maindoc/UBL-{$root}-2.1.xsd";

        if (! is_file($xsd)) {
            return [];
        }

        $document = new DOMDocument;
        $document->loadXML($xml);
        $previous = libxml_use_internal_errors(true);
        $document->schemaValidate($xsd);
        $errors = array_map(fn ($e): string => 'XSD: '.trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $errors;
    }

    /** @return list<string> */
    private function schematron(string $xml, string $artefacts, string $jar): array
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $spec = str_contains((string) $xpath->evaluate('string(/*/*[local-name()="CustomizationID"])'), 'selfbilling') ? 'selfbilling' : 'billing';
        $transaction = $document->documentElement->localName === 'CreditNote' ? 'trn-creditnote' : 'trn-invoice';

        $work = sys_get_temp_dir().'/pint-'.bin2hex(random_bytes(6));
        mkdir($work);
        file_put_contents($work.'/document.xml', $xml);
        $failures = [];

        try {
            foreach (self::RULES as $rules) {
                $result = Process::timeout(120)->run([
                    'java', '-cp', $jar, 'net.sf.saxon.Transform',
                    '-s:'.$work.'/document.xml',
                    '-xsl:'."{$artefacts}/{$spec}/{$transaction}/schematron/{$rules}",
                    '-o:'.$work.'/report.svrl',
                ]);

                $this->assertTrue($result->successful(), "Saxon failed on {$rules}:\n".$result->errorOutput());
                $failures = array_merge($failures, $this->failedAsserts((string) file_get_contents($work.'/report.svrl')));
            }
        } finally {
            array_map('unlink', glob($work.'/*') ?: []);
            rmdir($work);
        }

        return $failures;
    }

    /** @return list<string> */
    private function failedAsserts(string $svrl): array
    {
        $report = new DOMDocument;
        $report->loadXML($svrl);
        $xpath = new DOMXPath($report);
        $xpath->registerNamespace('svrl', 'http://purl.oclc.org/dsdl/svrl');
        $failures = [];

        foreach ($xpath->query('//svrl:failed-assert[@flag="fatal" or @flag="error"]') ?: [] as $assert) {
            $failures[] = preg_replace('/\s+/', ' ', trim($assert->textContent)).' at '.$assert->getAttribute('location');
        }

        return $failures;
    }

    /** @return array{string, string} */
    private function requireArtefacts(): array
    {
        $artefacts = rtrim(str_replace('\\', '/', (string) config('peppol.artefacts_path')), '/');
        $jar = (string) config('peppol.xslt_jar');

        if ($artefacts === '' || $jar === '') {
            $this->markTestSkipped('PEPPOL_ARTEFACTS_PATH and PEPPOL_XSLT_JAR are not set; PINT AE conformance not checked.');
        }

        if (! is_dir($artefacts.'/billing') || ! is_file($jar)) {
            $this->markTestSkipped("No PINT AE artefacts under {$artefacts}, or no jar at {$jar}.");
        }

        return [$artefacts, $jar];
    }
}
