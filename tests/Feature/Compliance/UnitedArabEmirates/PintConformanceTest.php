<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PeppolPreparer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\ScheduleRunner;
use App\Domains\Invoice\Services\Schedules;
use Carbon\CarbonImmutable;
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
 * jar with Saxon in it. Without both, these skip locally; CI's PINT AE job
 * downloads both, pinned by hash, and fails if they skip.
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
        foreach (['standard', 'foreign currency', 'zero-rated export', 'reverse charge', 'credit note', 'commercial invoice', 'commercial credit note', 'self-billed invoice', 'self-billed credit note', 'discount across categories', 'margin scheme', 'deemed supply', 'summary invoice', 'contract stage', 'free zone', 'buyer references', 'credit note with references'] as $case) {
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
     * A mixed basket through the API: standard-rated, zero-rated, exempt
     * under two reasons from Aligned-TaxExemptionCodes (IBR-167-AE) and out
     * of scope, under one document discount - a breakdown per category, one
     * exempt breakdown for both reasons (ALIGNED-IBRP-E-01), each line its
     * own reason.
     */
    public function test_mixed_categories_pass_official_rules(): void
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
            'discount_amount' => '40.00',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'lines' => [
                ['item_name' => 'Maintenance', 'quantity' => 2, 'unit_code' => 'DAY', 'unit_price' => '750.00', 'tax_category' => 'S', 'tax_rate' => 5],
                ['item_name' => 'Medicine', 'quantity' => 4, 'unit_code' => 'H87', 'unit_price' => '87.35', 'tax_category' => 'Z'],
                ['item_name' => 'Staff housing, October', 'quantity' => 1, 'unit_code' => 'MON', 'unit_price' => '4200.00', 'tax_category' => 'E', 'exempt_code' => 'DL8.46.2', 'exempt_reason' => 'Supply of residential units (lease or sale)'],
                ['item_name' => 'Staff bus', 'quantity' => 1, 'unit_code' => 'MON', 'unit_price' => '900.00', 'tax_category' => 'E', 'exempt_code' => 'DL8.46.4', 'exempt_reason' => 'Local passenger transport'],
                ['item_name' => 'Visa fees paid on your behalf', 'quantity' => 1, 'unit_code' => 'C62', 'unit_price' => '320.00', 'tax_category' => 'O'],
            ],
        ])->assertCreated()->json('data.invoice.id');
        $documentId = $api->postJson("/api/compliance/ae/invoices/{$id}/send")->assertOk()->json('data.document_id');

        $xml = PeppolDocument::query()->findOrFail($documentId)->xml;
        $failures = $this->validate($xml, $artefacts, $jar);

        $this->assertSame(4, substr_count($xml, '<cac:TaxSubtotal>'));
        $this->assertSame([], $failures, "PINT AE refused the mixed invoice:\n".implode("\n", $failures)."\n\n".$xml);

        // The exempt breakdown names neither reason rather than only the
        // first (IBT-121 is optional on it); each line names its own.
        $document = new DOMDocument;
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $this->assertSame(0.0, $xpath->evaluate("count(/*/*[local-name()='TaxTotal']/*[local-name()='TaxSubtotal']/*[local-name()='TaxCategory'][*[local-name()='ID']='E']/*[local-name()='TaxExemptionReasonCode'])"));
        $this->assertSame(['DL8.46.2', 'DL8.46.4'], array_map(fn ($node): string => $node->textContent, iterator_to_array($xpath->query("//*[local-name()='ClassifiedTaxCategory']/*[local-name()='TaxExemptionReasonCode']"))));

        // A breakdown per reason is refused: exactly one exempt breakdown.
        $exempt = $xpath->query("/*/*[local-name()='TaxTotal']/*[local-name()='TaxSubtotal'][*[local-name()='TaxCategory']/*[local-name()='ID']='E']")->item(0);
        $exempt->parentNode->insertBefore($exempt->cloneNode(true), $exempt);

        $this->assertNotEmpty(array_filter($this->validate((string) $document->saveXML(), $artefacts, $jar), fn (string $failure): bool => str_contains($failure, '[aligned-ibrp-e-01]')));
    }

    /**
     * Each payment means of PINT AE's list of codes used in the UAE
     * (UNCL4461.gc), drafted through the API: the codes the API takes are
     * codes the official rules pass (IBR-CL-16, IBR-192-AE for a transfer).
     *
     * @return iterable<string, array{string}>
     */
    public static function paymentMeans(): iterable
    {
        foreach (PintAe::PAYMENT_MEANS as $code) {
            yield "code {$code}" => [$code];
        }
    }

    #[DataProvider('paymentMeans')]
    public function test_payment_means_pass_official_rules(string $code): void
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
            'payment_means_code' => $code,
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'lines' => [['item_name' => 'Consulting', 'quantity' => 2, 'unit_code' => 'DAY', 'unit_price' => 500, 'tax_category' => 'S']],
        ])->assertCreated()->json('data.invoice.id');
        $documentId = $api->postJson("/api/compliance/ae/invoices/{$id}/send")->assertOk()->json('data.document_id');

        $xml = PeppolDocument::query()->findOrFail($documentId)->xml;
        $this->assertMatchesRegularExpression("#<cbc:PaymentMeansCode[^>]*>{$code}</cbc:PaymentMeansCode>#", $xml);

        $failures = $this->validate($xml, $artefacts, $jar);

        $this->assertSame([], $failures, "PINT AE refused payment means {$code}:\n".implode("\n", $failures)."\n\n".$xml);
    }

    /**
     * Discounts and charges, drafted through the API: line discounts and
     * line charges (IBG-27, IBG-28, IBR-147-AE), a document charge as an
     * amount and as a percentage of a base (IBG-21, ALIGNED-IBRP-058,
     * IBR-146-AE), a charge at each category including an exempt one with
     * its reason (IBR-169-AE) and an out-of-scope one with no rate
     * (ALIGNED-IBRP-O-07), and a charge on a credit note.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function charged(): iterable
    {
        $delivery = ['reason' => 'Delivery', 'reason_code' => 'DL', 'amount' => '35.00'];

        yield 'delivery and a service charge' => [[
            'charges' => [$delivery, ['reason' => 'Service charge', 'reason_code' => 'ADR', 'percent' => '10']],
        ]];
        yield 'line discount and line charges' => [[
            'lines' => [
                ['item_name' => 'Sofa', 'quantity' => 1, 'unit_code' => 'H87', 'unit_price' => '2499.99', 'tax_category' => 'S', 'discount_amount' => '250.00', 'charges' => [
                    ['reason' => 'Installation', 'reason_code' => 'IAA', 'amount' => '150.00'],
                    ['reason' => 'Packing', 'reason_code' => 'PC', 'percent' => '2.5'],
                ]],
                ['item_name' => 'Medicine', 'quantity' => 3, 'unit_code' => 'H87', 'unit_price' => '33.33', 'tax_category' => 'Z', 'discount_amount' => '3.33'],
            ],
        ]];
        yield 'charges at every category' => [[
            'discount_amount' => '20.00',
            'charges' => [
                $delivery,
                ['reason' => 'Freight', 'reason_code' => 'FC', 'amount' => '40.00', 'tax_category' => 'Z'],
                ['reason' => 'Bank fee', 'reason_code' => 'FI', 'amount' => '15.00', 'tax_category' => 'E', 'exempt_code' => 'DL8.46.1', 'exempt_reason' => 'Financial services'],
                ['reason' => 'Government fee', 'reason_code' => 'ABK', 'amount' => '10.00', 'tax_category' => 'O'],
            ],
        ]];
        yield 'a credit note with a charge' => [[
            'document_type' => 'credit_note',
            'billing_ref' => 'AE-INV-0001',
            'credit_reason' => 'DL8.61.1.A',
            'due_date' => null,
            'charges' => [$delivery],
        ]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('charged')]
    public function test_charges_pass_official_rules(array $overrides): void
    {
        [$artefacts, $jar] = $this->requireArtefacts();
        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();

        $organization = $this->uaeOrganization();
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);
        $api = $this->withToken(JWTAuth::claims(['org_id' => $organization->id, 'role' => 'admin'])->fromUser($user));

        $id = $api->postJson('/api/compliance/ae/invoices', array_filter([
            'issue_date' => '2026-10-10',
            'due_date' => '2026-11-09',
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'lines' => [['item_name' => 'Consulting', 'quantity' => 2, 'unit_code' => 'DAY', 'unit_price' => 500, 'tax_category' => 'S']],
            ...$overrides,
        ], fn (mixed $value): bool => $value !== null))->assertCreated()->json('data.invoice.id');
        $documentId = $api->postJson("/api/compliance/ae/invoices/{$id}/send")->assertOk()->json('data.document_id');

        $xml = PeppolDocument::query()->findOrFail($documentId)->xml;
        $this->assertStringContainsString('<cbc:ChargeIndicator>true</cbc:ChargeIndicator>', $xml);

        $failures = $this->validate($xml, $artefacts, $jar);

        $this->assertSame([], $failures, "PINT AE refused the charges:\n".implode("\n", $failures)."\n\n".$xml);
    }

    /** @return iterable<string, array{string, string, list<array{start_date: string, end_date: string}>|null}> */
    public static function recurring(): iterable
    {
        yield 'monthly service' => ['monthly', 'MTH', null];
        yield 'school term' => ['termly', 'OTH', [['start_date' => '2026-11-01', 'end_date' => '2027-01-31']]];
    }

    /**
     * A schedule's invoice for one period: a continuous supply (BTAE-02
     * position 5), its invoicing period (IBG-14) the period it bills and
     * how often it is billed (BTAE-06); a term, which has no code, as
     * Others with the invoice note saying so (IBR-160-AE).
     *
     * @param  list<array{start_date: string, end_date: string}>|null  $terms
     */
    #[DataProvider('recurring')]
    public function test_scheduled_invoice_passes_official_rules(string $frequency, string $code, ?array $terms): void
    {
        [$artefacts, $jar] = $this->requireArtefacts();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));
        $organization = $this->uaeOrganization();

        app(Schedules::class)->create($organization, [
            'name' => 'Noor maintenance', 'frequency' => $frequency, 'start_date' => '2026-11-01', 'terms' => $terms, 'due_days' => 30,
        ], [
            'document_type' => 'invoice', 'document_category' => 'tax', 'currency' => 'AED', 'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC', 'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi', 'country_code' => 'AE'],
            'buyer_endpoint' => '0235:1007654321', 'buyer_subdivision' => 'AUH', 'transaction_flags' => '00000000',
            'lines' => [['item_name' => 'Maintenance', 'quantity' => 1, 'unit_code' => 'MON', 'unit_price' => '1200.00', 'tax_category' => 'S', 'tax_rate' => 5]],
        ]);
        app(ScheduleRunner::class)->runDue();

        $invoice = Invoice::acrossTenants()->sole();
        $invoice->forceFill(['invoice_number' => 'AE-SCHEDULED-1', 'status' => 'issued'])->save();
        $xml = app(PeppolPreparer::class)->prepare($invoice->fresh(['lines', 'org']))->xml;

        $this->assertStringContainsString('<cbc:ProfileExecutionID>00001000</cbc:ProfileExecutionID>', $xml);
        $this->assertStringContainsString("<cbc:DescriptionCode>{$code}</cbc:DescriptionCode>", $xml);
        $this->assertSame([], $failures = $this->validate($xml, $artefacts, $jar), "PINT AE refused the {$frequency} invoice:\n".implode("\n", $failures)."\n\n".$xml);
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
            // A month's deliveries on one summary tax invoice, with its
            // invoicing period (Executive Regulation Art 59(6); IBR-138-AE).
            'summary invoice' => $this->uaeInvoice($this->uaeOrganization(), ['supply_date' => '2026-09-01', 'supply_end_date' => '2026-09-30'], null, ['transaction_flags' => '00010000']),
            // A stage of a contract, net of retention: a continuous supply
            // (UAE Electronic Invoicing Guidelines v1.1, scenario 5).
            'contract stage' => $this->uaeInvoice($this->uaeOrganization(), ['contract_reference' => 'C-2026-07'], null, ['transaction_flags' => '00001000']),
            'free zone' => $this->uaeInvoice($this->uaeOrganization(), [], null, ['transaction_flags' => '10000000', 'beneficiary_id' => '100765432100003']),
            'buyer references' => $this->uaeInvoice($this->uaeOrganization(), ['buyer_reference' => 'BR-001/23', 'order_reference' => 'PO-001/23', 'contract_reference' => 'CT-2026-07', 'payment_terms' => '30 days net']),
            'credit note with references' => $this->uaeInvoice($this->uaeOrganization(), ['document_type' => 'credit_note', 'billing_ref' => 'AE-INV-0001', 'due_date' => null, 'buyer_reference' => 'BR-001/23', 'order_reference' => 'PO-001/23', 'contract_reference' => 'CT-2026-07', 'payment_terms' => '30 days net'], null, ['credit_reason' => 'DL8.61.1.D']),
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
