<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Compliance\Networks\Peppol\Console\PintValidator;
use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\DTOs\AspError;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\NetworkServiceProvider;
use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use App\Domains\Compliance\Networks\Peppol\Simulator\PlaySimulatedMessage;
use App\Domains\Compliance\Networks\Peppol\Simulator\SimulatedAspGateway;
use App\Domains\Compliance\Networks\Peppol\Simulator\SimulatorCallbacks;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The service-provider simulator, played through the real sender, the
 * signed webhook routes and the status poll.
 *
 * The published rules' verdict is scripted here (StubPintValidator), so
 * this runs everywhere; SimulatorConformanceTest runs the same flows with
 * the real rules over the platform's own documents.
 */
class SimulatorTest extends TestCase
{
    use PlaysTheSimulator;
    use RefreshDatabase;

    private const SELLER = '0235:1001234567';

    private const BUYER = '0235:1007654321';

    private StubPintValidator $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSimulator();
        $this->judgedBy(new StubPintValidator);
    }

    public function test_simulator_is_the_configured_gateway(): void
    {
        $this->assertInstanceOf(SimulatedAspGateway::class, app(AspGateway::class));
    }

    public function test_delivered_document_plays_whole_lifecycle(): void
    {
        $document = $this->send($this->readyDocument());

        $this->assertSame('sent', $document->status);
        $this->assertStringStartsWith('sim-', (string) $document->asp_message_id);
        $this->assertSame([$document->xml], $this->rules->judged);

        $this->playNetwork();

        $document->refresh();
        $this->assertSame(['delivered', 'AP', 'reported'], [$document->status, $document->mls_code, $document->reporting_status]);
        $this->assertStringStartsWith('SIM-', (string) $document->authority_reference);
        $this->assertSame(['sent', 'delivered', 'reported'], array_column($this->callbacksTo(SimulatorCallbacks::STATUS_PATH), 'status'));
        $this->assertSame([200, 200, 200], $this->callbackAnswers);
    }

    public function test_failing_document_refused_with_rules(): void
    {
        $this->judgedBy(new StubPintValidator([new AspError('An Invoice MUST have an Invoice issue date (ibt-002).', 'ibr-003', AspError::FATAL, '/*:Invoice[1]')]));

        $document = $this->send($this->readyDocument());

        $this->assertSame('rejected', $document->status);
        $this->assertNull($document->asp_message_id);
        $this->assertSame([['code' => 'ibr-003', 'severity' => 'fatal', 'field' => '/*:Invoice[1]', 'message' => 'An Invoice MUST have an Invoice issue date (ibt-002).']], $document->errorList('errors'));
        Queue::assertNotPushed(PlaySimulatedMessage::class);
    }

    public function test_unknown_receiver_is_rejected(): void
    {
        $document = $this->send($this->readyDocument('0235:1999999901'));
        $this->playNetwork();

        $document->refresh();
        $this->assertSame('rejected', $document->status);
        $this->assertSame('PARTICIPANT-NOT-FOUND', $document->errors[0]['code']);
        $this->assertStringContainsString('0235:1999999901 is not a participant', $document->errors[0]['message']);
    }

    public function test_buyer_rejection_carries_its_code(): void
    {
        $document = $this->send($this->readyDocument('0235:1999999904'));
        $this->playNetwork();

        $document->refresh();
        $this->assertSame(['rejected', 'RE'], [$document->status, $document->mls_code]);
    }

    /** No verdict on the first attempt: the document waits, and the retry is taken. */
    public function test_unavailable_provider_is_tried_again(): void
    {
        $document = $this->send($this->readyDocument('0235:1999999902'));

        $this->assertSame(['ready', 1], [$document->status, $document->attempts]);
        $this->assertStringContainsString('simulated HTTP 503', (string) $document->last_error);
        $this->assertSame([], $this->rules->judged);

        $document = $this->send($document);
        $this->playNetwork();

        $document->refresh();
        $this->assertSame(['delivered', 'reported', 2, null], [$document->status, $document->reporting_status, $document->attempts, $document->last_error]);
    }

    /**
     * The first attempt is taken but its answer lost. The retry carries the
     * same idempotency key and gets the same message back: one filing, one
     * set of callbacks.
     */
    public function test_lost_answer_is_not_filed_twice(): void
    {
        $document = $this->send($this->readyDocument('0235:1999999903'));
        $this->assertSame('ready', $document->status);

        $document = $this->send($document);
        $this->assertSame('sent', $document->status);

        $this->playNetwork();

        $this->assertCount(1, $this->rules->judged);
        Queue::assertPushed(PlaySimulatedMessage::class, 3);
        $this->assertSame(
            array_fill(0, 3, $document->asp_message_id),
            array_column($this->callbacksTo(SimulatorCallbacks::STATUS_PATH), 'message_id'),
        );
        $this->assertSame(['delivered', 'reported'], [$document->fresh()->status, $document->fresh()->reporting_status]);
    }

    /** Sending a sent document again does nothing, here or at the provider. */
    public function test_duplicate_send_changes_nothing(): void
    {
        $document = $this->send($this->readyDocument());
        $messageId = $document->asp_message_id;

        $this->assertFalse(app(PeppolSender::class)->send((string) $document->id));
        $this->assertSame($messageId, app(AspGateway::class)->submit($document)->messageId);

        $this->assertCount(1, $this->rules->judged);
        Queue::assertPushed(PlaySimulatedMessage::class, 1);
    }

    /** A reserved endpoint: reported to the authority, never exchanged. */
    public function test_reserved_endpoint_is_only_reported(): void
    {
        $document = $this->send($this->readyDocument('0235:9900000098'));
        $this->playNetwork();

        $document->refresh();
        $this->assertSame(['not_exchanged', 'reported', null], [$document->status, $document->reporting_status, $document->mls_code]);
        $this->assertSame(['sent', 'reported'], array_column($this->callbacksTo(SimulatorCallbacks::STATUS_PATH), 'status'));
    }

    /** Sent to one of our own organizations, it arrives there as a received document. */
    public function test_delivery_here_arrives_as_inbound(): void
    {
        $buyer = $this->organizationAt(self::BUYER);
        $sent = $this->send($this->readyDocument());

        $this->playNetwork();

        $received = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->where('direction', 'in')->sole());
        $this->assertSame([$buyer->id, 'received', $sent->asp_message_id.'-in'], [$received->org_id, $received->status, $received->asp_message_id]);
        // The buyer's provider forwards the authority's confirmation of its report.
        $this->assertSame('reported', $received->reporting_status);
        $this->assertSame([$sent->xml, self::SELLER, self::BUYER], [$received->xml, $received->sender_endpoint, $received->receiver_endpoint]);
    }

    /** A callback before the platform knows the message is tried again later. */
    public function test_early_callback_is_retried(): void
    {
        $document = $this->readyDocument();
        $messageId = app(AspGateway::class)->submit($document)->messageId;

        $this->playNext();
        $this->assertSame([404], $this->callbackAnswers);
        $this->assertSame(1, Queue::pushed(PlaySimulatedMessage::class, fn (PlaySimulatedMessage $job): bool => $job->retry === 1)->count());

        PeppolDocument::withoutTenantScope(fn () => $document->forceFill(['status' => 'sent', 'asp_message_id' => $messageId])->save());
        $this->playNetwork();

        $this->assertSame(['delivered', 'reported'], [$document->fresh()->status, $document->fresh()->reporting_status]);
    }

    /** Callbacks lost for good: the status poll asks the simulator. */
    public function test_status_poll_finds_missed_callbacks(): void
    {
        $document = $this->send($this->readyDocument());

        $this->artisan('peppol:poll-status', ['--minutes' => 0])->assertSuccessful();

        $document->refresh();
        $this->assertSame(['delivered', 'AP', 'reported'], [$document->status, $document->mls_code, $document->reporting_status]);
    }

    public function test_step_delays_the_lifecycle(): void
    {
        config(['peppol.simulator.step_seconds' => 10]);
        $document = $this->send($this->readyDocument());

        $statuses = fn (): array => array_map(fn ($status) => $status->status, app(AspGateway::class)->status((string) $document->asp_message_id));

        $this->assertSame(['sent'], $statuses());
        $this->travel(25)->seconds();
        $this->assertSame(['delivered'], $statuses());
        $this->travel(10)->seconds();
        $this->assertSame(['delivered', 'reported'], $statuses());
    }

    /** No rules to judge by: no verdict, so the document waits rather than going out unjudged. */
    public function test_waits_without_the_rules(): void
    {
        config(['peppol.artefacts_path' => null, 'peppol.xslt_jar' => null]);
        $this->app->forgetInstance(PintValidator::class);

        $document = $this->send($this->readyDocument());

        $this->assertSame('ready', $document->status);
        $this->assertStringContainsString('PEPPOL_ARTEFACTS_PATH', (string) $document->last_error);
    }

    public function test_callback_signed_elsewhere_is_refused(): void
    {
        $this->postJson(SimulatorCallbacks::STATUS_PATH, ['message_id' => 'sim-x', 'status' => 'delivered'], [
            'X-Signature-Timestamp' => (string) time(),
            'X-Signature' => hash_hmac('sha256', time().'.{}', 'another secret'),
        ])->assertUnauthorized();
    }

    public function test_refused_in_production(): void
    {
        $refusals = [];
        $this->app['env'] = 'production';

        try {
            foreach ([fn () => app(AspGateway::class), fn () => (new NetworkServiceProvider($this->app))->boot()] as $attempt) {
                try {
                    $attempt();
                } catch (RuntimeException $e) {
                    $refusals[] = $e->getMessage();
                }
            }
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertCount(2, $refusals);
        $this->assertStringContainsString('must not be used when APP_ENV=production', $refusals[0]);
        $this->assertSame($refusals[0], $refusals[1]);
    }

    public function test_unknown_gateway_name_is_refused(): void
    {
        config(['peppol.gateway' => 'simulater']);

        $this->expectExceptionMessage('which is not a gateway');
        app(AspGateway::class);
    }

    /** The admin console's Countries page says the simulator stands in for a provider. */
    public function test_readiness_names_the_simulator(): void
    {
        $readiness = app(JurisdictionRegistry::class)->get('AE')->readiness();

        $this->assertStringStartsWith('Simulator, not for production', $readiness['Service provider']);
    }

    public function test_inbound_command_delivers_a_document(): void
    {
        $buyer = $this->organizationAt(self::BUYER);
        $file = tempnam(sys_get_temp_dir(), 'inbound');
        file_put_contents($file, $this->xml('0235:1999999999'));

        try {
            $this->artisan('peppol:simulate-inbound', ['receiver' => self::BUYER, '--file' => $file])->assertSuccessful();
        } finally {
            @unlink($file);
        }

        $received = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->where('direction', 'in')->sole());
        $this->assertSame([$buyer->id, self::SELLER, self::BUYER], [$received->org_id, $received->sender_endpoint, $received->receiver_endpoint]);
        $this->assertStringContainsString('<cbc:EndpointID schemeID="0235">1007654321</cbc:EndpointID>', $received->xml);
    }

    public function test_inbound_command_needs_the_simulator(): void
    {
        config(['peppol.gateway' => null]);

        $this->artisan('peppol:simulate-inbound', ['receiver' => self::BUYER])->assertFailed();
    }

    private function judgedBy(StubPintValidator $rules): void
    {
        $this->rules = $rules;
        $this->app->instance(PintValidator::class, $rules);
    }

    private function send(PeppolDocument $document): PeppolDocument
    {
        app(PeppolSender::class)->send((string) $document->id);

        return PeppolDocument::withoutTenantScope(fn () => $document->fresh());
    }

    private function readyDocument(string $receiver = self::BUYER): PeppolDocument
    {
        $seller = $this->organizationAt(self::SELLER);

        return PeppolDocument::withoutTenantScope(fn () => PeppolDocument::create([
            'org_id' => $seller->id,
            'direction' => PeppolDocument::DIRECTION_OUT,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            'xml' => $this->xml($receiver),
            'status' => 'ready',
            'sender_endpoint' => self::SELLER,
            'receiver_endpoint' => $receiver,
        ]));
    }

    private function organizationAt(string $endpoint): Organization
    {
        $existing = ComplianceProfile::withoutTenantScope(fn () => ComplianceProfile::query()->where('settings->endpoint_id', $endpoint)->first());

        if ($existing !== null) {
            return Organization::query()->findOrFail($existing->org_id);
        }

        $organization = Organization::create(['name' => 'Org '.$endpoint, 'country' => 'AE']);

        ComplianceProfile::create([
            'org_id' => $organization->id,
            'jurisdiction' => 'AE',
            'engine' => 'peppol',
            'status' => ComplianceProfile::STATUS_ACTIVE,
            'settings' => ['endpoint_id' => $endpoint],
        ]);

        return $organization;
    }

    private function xml(string $receiver): string
    {
        [$scheme, $id] = explode(':', $receiver, 2);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<cbc:CustomizationID>urn:peppol:pint:billing-1@ae-1</cbc:CustomizationID>'
            .'<cbc:ProfileID>urn:peppol:bis:billing</cbc:ProfileID>'
            .'<cbc:ID>SIM-001</cbc:ID>'
            .'<cac:AccountingSupplierParty><cac:Party><cbc:EndpointID schemeID="0235">1001234567</cbc:EndpointID></cac:Party></cac:AccountingSupplierParty>'
            ."<cac:AccountingCustomerParty><cac:Party><cbc:EndpointID schemeID=\"{$scheme}\">{$id}</cbc:EndpointID></cac:Party></cac:AccountingCustomerParty>"
            .'</Invoice>'."\n";
    }
}
