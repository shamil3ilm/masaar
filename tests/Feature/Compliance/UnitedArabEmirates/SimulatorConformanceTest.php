<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\ProviderAppointments;
use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Networks\Peppol\Console\PintValidator;
use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspGatewayResolver;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentHistory;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentStatus;
use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use App\Domains\Compliance\Networks\Peppol\Sending\ProviderOutage;
use App\Domains\Compliance\Networks\Peppol\Simulator\SimulatorCallbacks;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Compliance\Networks\Peppol\PlaysTheSimulator;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The UAE flow end to end through the service-provider simulator, with the
 * published PINT AE rules running inside it.
 *
 * Every outbound document here is issued the way the platform issues it -
 * the API, the portal, or InvoiceIssuer - sent by the real sender, judged by
 * the simulator with the official schematrons and the UBL 2.1 schema, and
 * followed through the signed callbacks to its final status. A document the
 * rules refuse ends rejected with the rule IDs, so a document that ends
 * delivered or reported has passed them.
 *
 * Needs PEPPOL_ARTEFACTS_PATH and PEPPOL_XSLT_JAR, as PintConformanceTest
 * does; skipped without them, and run by CI's PINT AE job, which fails if it
 * is skipped. SimulatorTest covers the same machinery everywhere, with the
 * rules' verdict scripted.
 */
class SimulatorConformanceTest extends TestCase
{
    use PintInvoices;
    use PlaysTheSimulator;
    use RefreshDatabase;

    private const BUYER = '0235:1007654321';

    private const TOKEN = 'simulator-conformance-token';

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(PintValidator::class)->available()) {
            $this->markTestSkipped('PEPPOL_ARTEFACTS_PATH and PEPPOL_XSLT_JAR are not set; the simulator cannot judge documents.');
        }

        config(['peppol.countries.AE.enabled' => true]);
        $this->useSimulator();
    }

    /**
     * Drafted and sent through the API, as an integrator would: delivered to
     * the buyer's access point and reported.
     */
    public function test_api_tax_invoice_is_delivered(): void
    {
        $document = $this->sendThroughApi($this->uaeOrganization());

        $this->assertDelivered($document);

        // Its own UUID, generated for the document, not the invoice's id
        // (UAE Electronic Invoicing Guidelines v1.1, p11 row 7); the
        // provider's id for it kept as a reference.
        $this->assertTrue(Str::isUuid((string) $document->document_uuid));
        $this->assertNotSame($document->invoice_id, $document->document_uuid);
        $this->assertStringContainsString('<cbc:UUID>'.$document->document_uuid.'</cbc:UUID>', (string) $document->xml);
        $this->assertStringStartsWith('SIMDOC-', (string) $document->fresh()->provider_reference);
        $this->assertSame(['sent', 'delivered', 'reported'], array_column($this->callbacksTo(SimulatorCallbacks::STATUS_PATH), 'status'));
    }

    /**
     * Each kind of document, issued by InvoiceIssuer and judged inside the
     * simulator.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'tax credit note' => ['credit note', 'delivered'];
        yield 'self-billed invoice' => ['self-billed invoice', 'delivered'];
        yield 'self-billed credit note' => ['self-billed credit note', 'delivered'];
        yield 'commercial invoice' => ['commercial invoice', 'delivered'];
        // No buyer endpoint: the reserved export endpoint, reported and never exchanged.
        yield 'zero-rated export' => ['zero-rated export', 'reported only'];
    }

    #[DataProvider('documents')]
    public function test_document_passes_inside_the_simulator(string $case, string $expected): void
    {
        $document = $this->sendIssued($this->draftFor($case));

        if ($expected === 'reported only') {
            $this->assertSame(['not_exchanged', 'reported', null, '0235:9900000099'], [$document->status, $document->reporting_status, $document->mls_code, $document->receiver_endpoint], $this->explain($document));

            return;
        }

        $this->assertDelivered($document);
    }

    /**
     * Discounts and charges through the API (IBG-20, IBG-21, IBG-27, IBG-28),
     * the shapes PintConformanceTest checks without a provider.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function charged(): iterable
    {
        yield 'delivery and a service charge' => [[
            'discount_amount' => '20.00',
            'charges' => [
                ['reason' => 'Delivery', 'reason_code' => 'DL', 'amount' => '35.00'],
                ['reason' => 'Service charge', 'reason_code' => 'ADR', 'percent' => '10'],
            ],
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
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('charged')]
    public function test_charges_pass_inside_the_simulator(array $overrides): void
    {
        $document = $this->sendThroughApi($this->uaeOrganization(), $overrides);

        $this->assertStringContainsString('<cbc:ChargeIndicator>true</cbc:ChargeIndicator>', (string) $document->xml);
        $this->assertDelivered($document);
    }

    /** A document that breaks a rule is refused with the rule's own ID and text. */
    public function test_broken_document_refused_with_rule_ids(): void
    {
        $document = app(InvoiceIssuer::class)->issue($this->draftFor('standard'), send: false);
        $broken = (string) preg_replace('#<cbc:IssueDate>[^<]*</cbc:IssueDate>#', '', (string) $document->xml, 1);
        $document->forceFill(['xml' => $broken])->save();

        $document = $this->sendNow($document);

        $this->assertSame('rejected', $document->status);
        $this->assertNull($document->asp_message_id);
        // Each error with its rule ID, severity and where in the document.
        $this->assertContains(
            ['code' => 'ibr-003', 'severity' => 'fatal', 'field' => "/*:Invoice[namespace-uri()='urn:oasis:names:specification:ubl:schema:xsd:Invoice-2'][1]", 'message' => 'An Invoice MUST have an Invoice issue date (ibt-002).'],
            $document->errorList('errors'),
        );
    }

    /**
     * A buyer not on the network is looked up before the invoice is issued
     * (UAE Electronic Invoicing Guidelines v1.1, p11 rows 5-6; programme
     * presentation p7): refused with what to do, the invoice still a draft,
     * nothing sent.
     */
    public function test_unknown_buyer_is_refused_before_issue(): void
    {
        $draft = $this->draftFor('standard', ['buyer_endpoint' => '0235:1999999901']);

        try {
            app(InvoiceIssuer::class)->issue($draft);
            $this->fail('An invoice to a buyer not on the network was issued.');
        } catch (IssuanceRefusedException $e) {
            $this->assertStringContainsString('0235:1999999901 is not registered on the Peppol network', implode(' ', $e->problems));
            $this->assertStringContainsString('0235:9900000098', implode(' ', $e->problems));
        }

        $this->assertSame('draft', $draft->fresh()->status->value);
        $this->assertSame(0, PeppolDocument::acrossTenants()->count());
        $this->assertSame([], $this->callbacksTo(SimulatorCallbacks::STATUS_PATH));
    }

    public function test_buyer_access_point_rejection(): void
    {
        $document = $this->sendIssued($this->draftFor('standard', ['buyer_endpoint' => '0235:1999999904']));

        $this->assertSame(['rejected', 'RE'], [$document->status, $document->mls_code]);
    }

    /**
     * The report to the authority fails after delivery: its own status, the
     * delivery untouched, and asked again until the authority confirms it
     * (UAE Electronic Invoicing Guidelines v1.1, p10 step 8, p41 item vi, p45).
     */
    public function test_failed_report_is_retried_to_reported(): void
    {
        $document = $this->sendIssued($this->draftFor('standard', ['buyer_endpoint' => '0235:1999999905']));

        $this->assertSame(['delivered', 'report_failed'], [$document->status, $document->reporting_status]);
        $this->assertNotEmpty($document->reporting_errors);

        $this->travel(2)->hours();
        $this->artisan('peppol:retry-reports')->assertSuccessful();
        $this->assertSame('pending', $document->fresh()->reporting_status);
        $this->playNetwork();

        $document = $document->fresh();
        $this->assertSame(['delivered', 'reported'], [$document->status, $document->reporting_status]);
        $this->assertNotNull($document->authority_reference);
    }

    /** The authority's confirmation first, the buyer's provider's after: both kept. */
    public function test_early_report_keeps_later_delivery(): void
    {
        $document = $this->sendIssued($this->draftFor('standard', ['buyer_endpoint' => '0235:1999999906']));

        $this->assertSame(['sent', 'reported', 'delivered'], array_column($this->callbacksTo(SimulatorCallbacks::STATUS_PATH), 'status'));
        $this->assertDelivered($document);
    }

    /**
     * A buyer not yet on e-invoicing: the reserved endpoint, reported and
     * never exchanged, and done once reported - not asked about for a
     * fortnight (UAE Electronic Invoicing Guidelines v1.1, p23).
     */
    public function test_reserved_endpoint_is_done_once_reported(): void
    {
        $document = $this->sendIssued($this->draftFor('standard', ['buyer_endpoint' => '0235:9900000098']));

        $this->assertSame(['not_exchanged', 'reported'], [$document->status, $document->reporting_status], $this->explain($document));

        $this->travel(1)->hours();
        $this->artisan('peppol:poll-status')->expectsOutputToContain('Asked about 0 of 0 documents.')->assertSuccessful();
    }

    /**
     * The provider took it, then the worker died before recording the
     * answer: found by its own reference, recorded as sent, and delivered -
     * not sent twice (UAE Electronic Invoicing Guidelines v1.1, p46).
     */
    public function test_stuck_document_is_found_by_reference(): void
    {
        $document = app(InvoiceIssuer::class)->issue($this->draftFor('standard'), send: false);
        $claimed = app(DocumentStatus::class)->transition((string) $document->id, 'sending');
        $messageId = app(AspGateway::class)->submit($claimed)->messageId;

        $this->travel(20)->minutes();
        $this->artisan('peppol:recover-sending')->expectsOutputToContain('Found at the provider: 1.')->assertSuccessful();
        $this->playNetwork();

        $document = $document->fresh();
        $this->assertSame($messageId, $document->asp_message_id);
        $this->assertDelivered($document);
    }

    /** Claimed but never handed over: the provider has nothing, so it is sent again. */
    public function test_never_received_document_is_sent_again(): void
    {
        $document = app(InvoiceIssuer::class)->issue($this->draftFor('standard'), send: false);
        app(DocumentStatus::class)->transition((string) $document->id, 'sending');

        $this->travel(20)->minutes();
        $this->artisan('peppol:recover-sending')->expectsOutputToContain('Sent again: 1.')->assertSuccessful();
        $this->playNetwork();

        $this->assertDelivered($document->fresh());
    }

    /**
     * The provider down for several attempts in a row: an outage, during
     * which no document is given up on even past its window; when the
     * provider answers again, what waits is sent at once (UAE Electronic
     * Invoicing Guidelines v1.1, p45-46).
     */
    public function test_outage_is_ridden_out(): void
    {
        $document = app(InvoiceIssuer::class)->issue($this->draftFor('standard', ['buyer_endpoint' => '0235:1999999907']));
        $outage = app(ProviderOutage::class);

        $this->sendNow($document);
        $this->sendNow($document);
        $this->assertTrue($outage->active());

        // Long past the retry window, still unanswered: still waiting, not failed.
        $this->travel(80)->hours();
        $this->sendNow($document);
        $this->assertSame(['ready', 4], [$document->fresh()->status, $document->fresh()->attempts]);

        $this->assertDelivered($this->sendNow($document));
        $this->assertFalse($outage->active());
    }

    /**
     * The organization's appointed provider carries its e-invoices: the
     * platform's partner, recorded as appointed (UAE Electronic Invoicing
     * Guidelines v1.1, p14), resolved per organization.
     */
    public function test_appointed_provider_carries_the_invoice(): void
    {
        $draft = $this->draftFor('standard');
        $organization = Organization::query()->findOrFail($draft->org_id);
        app(ProviderAppointments::class)->record($organization, ['provider' => 'platform', 'status' => 'appointed', 'appointed_at' => now()->toDateString()], null);

        $this->assertSame('platform', app(AspGatewayResolver::class)->providerFor((string) $organization->id));
        $this->assertDelivered($this->sendIssued($draft));
    }

    /** No answer to the first attempt: the document waits, and the retry is delivered. */
    public function test_failure_then_retry_is_delivered(): void
    {
        $document = $this->sendIssued($this->draftFor('standard', ['buyer_endpoint' => '0235:1999999902']));

        $this->assertSame(['ready', 1], [$document->status, $document->attempts]);

        $this->assertDelivered($this->sendNow($document));
    }

    /**
     * A send asked for twice, and a retry after a lost answer: one document,
     * one message at the provider, one set of callbacks.
     */
    public function test_duplicate_send_is_filed_once(): void
    {
        [$api, $id] = $this->draftThroughApi($this->uaeOrganization(), ['buyer_endpoint' => '0235:1999999903']);

        $first = $api->postJson("/api/compliance/ae/invoices/{$id}/send")->assertOk()->json('data.document_id');
        $second = $api->postJson("/api/compliance/ae/invoices/{$id}/send")->assertOk()->json('data.document_id');
        $this->assertSame($first, $second);

        // The first attempt was taken but its answer lost; the retry gets the same message.
        $document = PeppolDocument::acrossTenants()->findOrFail($first);
        $this->assertSame('ready', $document->status);
        $document = $this->sendNow($document);
        $this->playNetwork();

        $this->assertDelivered($document->fresh());
        $this->assertSame(
            array_fill(0, 3, $document->asp_message_id),
            array_column($this->callbacksTo(SimulatorCallbacks::STATUS_PATH), 'message_id'),
        );
        $this->assertSame(1, PeppolDocument::acrossTenants()->where('direction', 'out')->count());
    }

    /**
     * Sent to a buyer that is one of our organizations: it arrives in that
     * organization's Received list, as it travelled.
     */
    public function test_invoice_to_our_buyer_arrives_inbound(): void
    {
        $buyer = $this->uaeOrganization(['endpoint_id' => self::BUYER, 'trn' => '100765432100003', 'tin' => '1007654321']);
        $buyer->update(['name' => 'Noor Electronics LLC']);
        $sent = $this->sendIssued($this->draftFor('standard'));
        $number = Invoice::acrossTenants()->findOrFail($sent->invoice_id)->invoice_number;

        $received = PeppolDocument::acrossTenants()->where('direction', 'in')->sole();
        $this->assertSame([$buyer->id, 'received', $sent->xml], [$received->org_id, $received->status, $received->xml]);
        // Its report, by the buyer's provider, confirmed and forwarded (UAE
        // Electronic Invoicing Guidelines v1.1, p11 steps 9 and 11).
        $this->assertSame('reported', $received->reporting_status);
        $this->assertNotNull($received->authority_reference);

        $this->portal($buyer, 'GET', '/portal/received')->assertOk()->assertSee($number);
        $this->portal($buyer, 'GET', "/portal/received/{$received->id}")->assertOk()->assertSee('Gulf Trading LLC');
    }

    /** Another supplier's invoice, the specification's own example, delivered by the simulator. */
    public function test_supplier_example_arrives_inbound(): void
    {
        $buyer = $this->uaeOrganization(['endpoint_id' => self::BUYER]);

        $this->artisan('peppol:simulate-inbound', ['receiver' => self::BUYER])->assertSuccessful();

        $received = PeppolDocument::acrossTenants()->where('direction', 'in')->sole();
        $this->assertSame([$buyer->id, self::BUYER, 'Invoice'], [$received->org_id, $received->receiver_endpoint, $received->document_type]);
        $this->portal($buyer, 'GET', "/portal/received/{$received->id}")->assertOk();
    }

    /**
     * The portal, start to finish: drafted on the UAE form, issued from the
     * invoice page, and the page following the document to Reported.
     */
    public function test_portal_invoice_reaches_reported(): void
    {
        $seller = $this->uaeOrganization();

        $this->portal($seller, 'POST', '/portal/invoices/uae', $this->portalForm())->assertSessionHasNoErrors();
        $invoice = Invoice::acrossTenants()->where('org_id', $seller->id)->sole();

        $this->portal($seller, 'POST', "/portal/invoices/{$invoice->id}/issue")->assertSessionHas('success');
        $this->portal($seller, 'GET', "/portal/invoices/{$invoice->id}")->assertOk()->assertSee(__('Sent'))->assertSee('data-simulated', false);

        $this->playNetwork();

        $this->portal($seller, 'GET', "/portal/invoices/{$invoice->id}")->assertOk()->assertSee(__('Reported to the authority'));
        $this->assertDelivered(PeppolDocument::acrossTenants()->where('invoice_id', $invoice->id)->sole());
    }

    private function assertDelivered(PeppolDocument $document): void
    {
        $document = $document->fresh();
        $this->assertRecordAddsUp($document);

        $this->assertSame(['delivered', 'AP', null, 'reported'], [$document->status, $document->mls_code, $document->errors, $document->reporting_status], $this->explain($document));
        $this->assertNotNull($document->authority_reference);
    }

    /**
     * The transmission record derives the document's state (UAE Electronic
     * Invoicing Guidelines v1.1, p12), and keeps every callback as it came.
     */
    private function assertRecordAddsUp(PeppolDocument $document): void
    {
        $history = app(DocumentHistory::class);

        $this->assertSame(
            $history->replay((string) $document->id),
            $document->only(['status', 'reporting_status', 'mls_code', 'asp_message_id', 'authority_reference']),
        );

        $callbacks = $history->of((string) $document->id)->where('source', 'callback');
        $this->assertNotEmpty($callbacks);
        $this->assertTrue($callbacks->every(fn ($event): bool => strlen((string) $event->payload_digest) === 64));
    }

    private function explain(PeppolDocument $document): string
    {
        return "The simulator answered:\n".json_encode($document->errorList('errors'), JSON_PRETTY_PRINT)."\n".$document->last_error."\n\n".$document->xml;
    }

    /**
     * Issue a draft, as the invoice page does, and play the network.
     */
    private function sendIssued(Invoice $draft): PeppolDocument
    {
        $document = app(InvoiceIssuer::class)->issue($draft);
        $this->playNetwork();

        return $document->fresh();
    }

    /**
     * One more attempt now, as the send job's next try would, and play the network.
     */
    private function sendNow(PeppolDocument $document): PeppolDocument
    {
        app(PeppolSender::class)->send((string) $document->id);
        $this->playNetwork();

        return $document->fresh();
    }

    /**
     * @param  array<string, mixed>  $data  jurisdiction_data over the case's
     */
    private function draftFor(string $case, array $data = []): Invoice
    {
        $supplier = ['buyer_legal_registration' => ['type' => 'TL', 'id' => 'CN-7654321', 'authority' => 'Abu Dhabi Department of Economic Development']];
        $organization = $this->uaeOrganization($case === 'commercial invoice' ? ['trn' => null] : []);
        $line = fn (array $overrides = []): array => $this->uaeLine($overrides);

        [$attributes, $lines, $caseData] = match ($case) {
            'standard' => [[], null, []],
            'credit note' => [['document_type' => 'credit_note', 'billing_ref' => 'AE-INV-0001', 'due_date' => null], null, ['credit_reason' => 'DL8.61.1.D']],
            'self-billed invoice' => [['is_self_billed' => true], null, $supplier],
            'self-billed credit note' => [['is_self_billed' => true, 'document_type' => 'credit_note', 'billing_ref' => 'AE-SB-0001', 'due_date' => null], null, ['credit_reason' => 'DL8.61.1.A', ...$supplier]],
            'commercial invoice' => [[], [$line(['tax_category' => 'O', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 1000])], ['document_category' => 'commercial_invoice', ...$supplier]],
            'zero-rated export' => [
                ['buyer_name' => 'Export Traders Inc', 'buyer_vat_number' => null, 'buyer_address' => ['street' => '5th Avenue', 'city' => 'New York', 'country_code' => 'US']],
                [$line(['tax_category' => 'Z', 'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 1000])],
                ['transaction_flags' => '00000001', 'buyer_endpoint' => null, 'buyer_subdivision' => 'NY'],
            ],
        };

        $issued = $this->uaeInvoice($organization, ['invoice_number' => null, ...$attributes], $lines, [...$caseData, ...$data]);

        // Back to a draft, with the totals of its lines, so InvoiceIssuer
        // issues it the way it issues every invoice.
        $net = (float) $issued->lines->sum('net_amount');
        $tax = (float) $issued->lines->sum('tax_amount');
        Invoice::acrossTenants()->whereKey($issued->id)->update([
            'status' => 'draft',
            'subtotal' => number_format($net, 2, '.', ''),
            'tax_amount' => number_format($tax, 2, '.', ''),
            'total' => number_format($net + $tax, 2, '.', ''),
        ]);

        return Invoice::acrossTenants()->findOrFail($issued->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sendThroughApi(Organization $organization, array $overrides = []): PeppolDocument
    {
        [$api, $id] = $this->draftThroughApi($organization, $overrides);
        $documentId = $api->postJson("/api/compliance/ae/invoices/{$id}/send")->assertOk()->json('data.document_id');

        $this->playNetwork();

        return PeppolDocument::acrossTenants()->findOrFail($documentId);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{self, string}
     */
    private function draftThroughApi(Organization $organization, array $overrides = []): array
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);
        $api = $this->withToken(JWTAuth::claims(['org_id' => $organization->id, 'role' => 'admin'])->fromUser($user));

        $id = $api->postJson('/api/compliance/ae/invoices', [
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
            'buyer_endpoint' => self::BUYER,
            'buyer_subdivision' => 'AUH',
            'lines' => [['item_name' => 'Consulting', 'description' => 'Consulting services', 'quantity' => 2, 'unit_code' => 'DAY', 'unit_price' => 500, 'tax_category' => 'S', 'tax_rate' => 5]],
            ...$overrides,
        ])->assertCreated()->json('data.invoice.id');

        return [$api, (string) $id];
    }

    /**
     * A portal request by an admin of the organization, as a browser makes it.
     *
     * @param  array<string, mixed>  $data
     */
    private function portal(Organization $organization, string $method, string $uri, array $data = []): TestResponse
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)
            ->withSession(['portal_org_id' => $organization->id, '_token' => self::TOKEN])
            ->call($method, $uri, $method === 'GET' ? [] : [...$data, '_token' => self::TOKEN]));
    }

    /**
     * The UAE form as a browser sends it.
     *
     * @return array<string, mixed>
     */
    private function portalForm(): array
    {
        return [
            'invoice_number' => '',
            'document_type' => 'invoice',
            'document_category' => 'tax',
            'issue_date' => now()->toDateString(),
            'supply_date' => '',
            'supply_end_date' => '',
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'AED',
            'exchange_rate' => '',
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_name_ar' => '',
            'buyer_vat_number' => '100765432100003',
            'buyer_endpoint_choice' => 'own',
            'buyer_endpoint' => self::BUYER,
            'buyer_address' => ['street' => 'Khalifa Street', 'building_number' => '', 'city' => 'Abu Dhabi', 'postal_code' => '', 'country_code' => 'AE'],
            'buyer_subdivision' => 'AUH',
            'buyer_legal_registration' => ['type' => '', 'id' => '', 'authority' => ''],
            'beneficiary_id' => '',
            'principal_id' => '',
            'delivery_address' => ['street' => '', 'city' => '', 'subdivision' => '', 'postal_code' => '', 'country_code' => ''],
            'lines' => [[
                'item_name' => 'Consulting', 'description' => 'Consulting services, October', 'quantity' => '2', 'unit_code' => 'DAY',
                'unit_price' => '500', 'tax_category' => 'S', 'tax_rate' => '5', 'exempt_code' => '', 'goods_type' => '', 'item_id' => '',
            ]],
            'billing_ref' => '',
            'credit_reason' => '',
            'discount_amount' => '',
            'notes' => '',
        ];
    }
}
