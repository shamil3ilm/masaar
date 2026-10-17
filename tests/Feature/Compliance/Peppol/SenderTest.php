<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Peppol\Exceptions\AspRefusedException;
use App\Domains\Compliance\Peppol\Exceptions\AspUnavailableException;
use App\Domains\Compliance\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Peppol\Gateways\NullAspGateway;
use App\Domains\Compliance\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\Services\PeppolSender;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Handing ready documents to the service provider: once, with retries while
 * it gives no verdict, and not at all while none is configured.
 */
class SenderTest extends TestCase
{
    use RefreshDatabase;

    private FakeAspGateway $asp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asp = new FakeAspGateway;
        $this->app->instance(AspGateway::class, $this->asp);
    }

    public function test_ready_document_is_sent(): void
    {
        $document = $this->readyDocument();

        SendPeppolDocument::dispatch($document->id);

        $document->refresh();
        $this->assertSame('sent', $document->status);
        $this->assertSame('fake-'.$document->id, $document->asp_message_id);
        $this->assertSame(1, $document->attempts);
        $this->assertSame([$document->id], $this->asp->submitted);
    }

    /**
     * The UAE switched off stops new issuing, not sending what was already
     * issued: a ready document still goes to the service provider.
     */
    public function test_sending_continues_while_switched_off(): void
    {
        $document = $this->readyDocument();
        app(JurisdictionAvailability::class)->set('AE', false, 'ops@masaar.test');

        SendPeppolDocument::dispatch($document->id);

        $this->assertSame('sent', $document->fresh()->status);
        $this->assertSame([$document->id], $this->asp->submitted);
    }

    /**
     * A second job for the same document - a duplicate dispatch, a redelivery
     * - finds it sent and does nothing.
     */
    public function test_a_document_is_never_sent_twice(): void
    {
        $document = $this->readyDocument();

        SendPeppolDocument::dispatch($document->id);
        SendPeppolDocument::dispatch($document->id);
        app(PeppolSender::class)->send($document->id);

        $this->assertCount(1, $this->asp->submitted);
        $this->assertSame(1, $document->fresh()->attempts);
    }

    /**
     * One in 'sending' may already be with the provider: a worker that died
     * mid-call must not have it sent again by the next.
     */
    public function test_a_document_in_flight_is_left(): void
    {
        $document = $this->readyDocument(['status' => 'sending', 'attempts' => 1]);

        $this->assertFalse(app(PeppolSender::class)->send($document->id));

        $this->assertSame([], $this->asp->submitted);
        $this->assertSame('sending', $document->fresh()->status);
    }

    /**
     * No verdict goes back to ready and is tried again, until the attempts
     * run out and the document is failed with the reason.
     */
    public function test_no_verdict_retries_then_fails(): void
    {
        $document = $this->readyDocument();
        $sender = app(PeppolSender::class);

        for ($attempt = 1; $attempt < PeppolSender::MAX_ATTEMPTS; $attempt++) {
            $this->asp->failNextWith(new AspUnavailableException('HTTP 503'));

            $this->assertTrue($sender->send($document->id));
            $this->assertSame('ready', $document->fresh()->status);
        }

        $this->asp->failNextWith(new AspUnavailableException('HTTP 503'));

        $this->assertFalse($sender->send($document->id));

        $document->refresh();
        $this->assertSame('failed', $document->status);
        $this->assertSame(PeppolSender::MAX_ATTEMPTS, $document->attempts);
        $this->assertSame('HTTP 503', $document->last_error);
        $this->assertNull($document->asp_message_id);
    }

    /**
     * A retry after no verdict that then succeeds is sent once, with the
     * error cleared.
     */
    public function test_retry_after_outage_sends(): void
    {
        $document = $this->readyDocument();
        $this->asp->failNextWith(new AspUnavailableException('unreachable'));

        SendPeppolDocument::dispatch($document->id);
        $this->assertSame('ready', $document->fresh()->status);

        SendPeppolDocument::dispatch($document->id);

        $document->refresh();
        $this->assertSame('sent', $document->status);
        $this->assertSame(2, $document->attempts);
        $this->assertNull($document->last_error);
    }

    public function test_refusal_is_rejected_not_retried(): void
    {
        $document = $this->readyDocument();
        $this->asp->failNextWith(new AspRefusedException('refused', ['BR-AE-01 buyer endpoint missing']));

        $this->assertFalse(app(PeppolSender::class)->send($document->id));

        $document->refresh();
        $this->assertSame('rejected', $document->status);
        $this->assertSame(['BR-AE-01 buyer endpoint missing'], $document->errors);
    }

    /**
     * No provider: the document waits for a manual upload, and the wait is
     * not counted against it.
     */
    public function test_null_gateway_leaves_document_ready(): void
    {
        $this->app->instance(AspGateway::class, new NullAspGateway);
        $document = $this->readyDocument();

        SendPeppolDocument::dispatch($document->id);

        $document->refresh();
        $this->assertSame('ready', $document->status);
        $this->assertSame(0, $document->attempts);
        $this->assertNull($document->asp_message_id);
    }

    /**
     * The queue giving up fails a waiting document, never one in flight.
     */
    public function test_giving_up_spares_in_flight(): void
    {
        $waiting = $this->readyDocument();
        $inFlight = $this->readyDocument(['status' => 'sending']);
        $sender = app(PeppolSender::class);

        $sender->giveUp($waiting->id, 'max attempts');
        $sender->giveUp($inFlight->id, 'max attempts');

        $this->assertSame('failed', $waiting->fresh()->status);
        $this->assertSame('sending', $inFlight->fresh()->status);
    }

    public function test_sending_uses_its_own_queue(): void
    {
        config(['peppol.asp.queue' => 'renamed-peppol']);
        Queue::fake();

        app(PeppolSender::class)->queue($this->readyDocument());

        Queue::assertPushedOn('renamed-peppol', SendPeppolDocument::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    /**
     * Failed is not final for an operator: once the cause is fixed the
     * document starts again from its first attempt.
     */
    public function test_failed_document_can_be_resent(): void
    {
        Queue::fake();
        $document = $this->readyDocument(['status' => 'failed', 'attempts' => PeppolSender::MAX_ATTEMPTS, 'last_error' => 'HTTP 401']);

        $this->artisan('peppol:resend', ['document' => $document->id])->assertSuccessful();

        $document->refresh();
        $this->assertSame('ready', $document->status);
        $this->assertSame(0, $document->attempts);
        $this->assertNull($document->last_error);
        Queue::assertPushed(SendPeppolDocument::class, fn (SendPeppolDocument $job) => $job->documentId === $document->id);
    }

    public function test_only_failed_documents_are_resent(): void
    {
        Queue::fake();
        $document = $this->readyDocument(['status' => 'sent', 'asp_message_id' => 'msg-1']);

        $this->artisan('peppol:resend', ['document' => $document->id])->assertFailed();

        $this->assertSame('sent', $document->fresh()->status);
        Queue::assertNothingPushed();
    }

    private function readyDocument(array $overrides = []): PeppolDocument
    {
        $organization = Organization::create(['name' => 'Gulf Trading', 'country' => 'AE']);

        return PeppolDocument::withoutTenantScope(fn () => PeppolDocument::create([
            'org_id' => $organization->id,
            'direction' => PeppolDocument::DIRECTION_OUT,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            'xml' => '<Invoice/>',
            'status' => 'ready',
            'sender_endpoint' => '0235:1000000001',
            'receiver_endpoint' => '0235:1000000002',
            ...$overrides,
        ]));
    }
}
