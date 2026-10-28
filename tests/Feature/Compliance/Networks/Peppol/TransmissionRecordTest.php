<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspStatus;
use App\Domains\Compliance\Networks\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocumentEvent;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentHistory;
use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * A Peppol document's transmission record (Electronic Invoicing Guidelines
 * v1.1, p12: the data on issuance, transmission and receipt is retained):
 * every move, callback and lookup, append-only, and the document's state
 * derived from it.
 */
class TransmissionRecordTest extends TestCase
{
    use RefreshDatabase;

    private FakeAspGateway $asp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asp = new FakeAspGateway('record-secret');
        $this->app->instance(AspGateway::class, $this->asp);
    }

    public function test_every_move_and_callback_is_kept(): void
    {
        $document = $this->document();
        app(PeppolSender::class)->send((string) $document->id);
        $messageId = 'fake-'.$document->id;

        $body = (string) json_encode(['message_id' => $messageId, 'status' => 'delivered', 'mls_code' => 'AP']);
        $this->postCallback($body)->assertOk();
        // The same callback again: kept, and changes nothing.
        $this->postCallback($body)->assertOk();

        $events = app(DocumentHistory::class)->of((string) $document->id);

        $this->assertSame(
            [['system', 'state', 'ready', true], ['submission', 'delivery', 'sending', true], ['submission', 'delivery', 'sent', true], ['callback', 'delivery', 'delivered', true], ['callback', 'delivery', 'delivered', false]],
            $events->map(fn (PeppolDocumentEvent $event): array => [$event->source, $event->track, $event->status, $event->applied])->all(),
        );
        $this->assertSame(hash('sha256', $body), $events[3]->payload_digest);
        $this->assertSame(['AP', $messageId], [$events[3]->mls_code, $events[3]->asp_message_id]);
        $this->assertSame('pending', $events[2]->reporting_status);
    }

    /** The document's state is what its applied events add up to. */
    public function test_state_is_derived_from_the_record(): void
    {
        $document = $this->document();
        app(PeppolSender::class)->send((string) $document->id);
        $this->asp->answer(new AspStatus('fake-'.$document->id, 'reported', null, [], 'TDD-4'), new AspStatus('fake-'.$document->id, 'delivered', 'AP'));
        $document->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $this->artisan('peppol:poll-status')->assertSuccessful();

        $document->refresh();
        $this->assertSame(
            $document->only(['status', 'reporting_status', 'mls_code', 'asp_message_id', 'authority_reference']),
            app(DocumentHistory::class)->replay((string) $document->id),
        );
        $this->assertSame(['delivered', 'reported', 'TDD-4'], [$document->status, $document->reporting_status, $document->authority_reference]);
        $this->assertSame(2, app(DocumentHistory::class)->of((string) $document->id)->where('source', 'poll')->count());
    }

    public function test_record_is_never_changed_or_removed(): void
    {
        $event = app(DocumentHistory::class)->of((string) $this->document()->id)->sole();

        foreach ([fn () => $event->forceFill(['status' => 'delivered'])->save(), fn () => $event->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('A transmission record event was changed or removed.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('never', $e->getMessage());
            }
        }

        $this->assertSame('ready', $event->fresh()->status);
    }

    /**
     * Documents from before the record get their current state as one event.
     * SQLite only: dropping and creating a table inside the test's transaction
     * is safe there, and on MySQL would commit it.
     */
    public function test_existing_documents_are_backfilled(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Runs the migration inside the test transaction, which only SQLite allows.');
        }

        $document = $this->document();
        $document->forceFill(['status' => 'delivered', 'reporting_status' => 'reported', 'mls_code' => 'AP', 'asp_message_id' => 'msg-old'])->saveQuietly();
        $migration = require database_path('migrations/1140_peppol_document_events.php');

        // As before the record existed: the document, and no events.
        $migration->down();
        $migration->up();

        $event = PeppolDocumentEvent::query()->where('document_id', $document->id)->sole();
        $this->assertSame(['backfill', 'state', 'delivered', 'reported', 'AP', 'msg-old', true], [
            $event->source, $event->track, $event->status, $event->reporting_status, $event->mls_code, $event->asp_message_id, $event->applied,
        ]);
    }

    private function postCallback(string $body): TestResponse
    {
        $timestamp = (string) time();

        return $this->call('POST', '/api/peppol/webhooks/status', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => (new WebhookSignature('record-secret'))->sign($timestamp, $body),
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        ], $body);
    }

    private function document(): PeppolDocument
    {
        $organization = Organization::create(['name' => 'Gulf Trading', 'country' => 'AE']);

        return PeppolDocument::acrossTenants()->create([
            'org_id' => $organization->id,
            'direction' => PeppolDocument::DIRECTION_OUT,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            'xml' => '<Invoice/>',
            'status' => 'ready',
            'receiver_endpoint' => '0235:1007654321',
        ]);
    }
}
