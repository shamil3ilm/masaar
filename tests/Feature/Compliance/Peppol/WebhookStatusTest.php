<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Compliance\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Peppol\Gateways\AspStatus;
use App\Domains\Compliance\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\Services\DocumentStatus;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What a status callback - or a status lookup, when the callback was lost -
 * does to a sent document. Callbacks arrive late, twice and out of order, so
 * a status only ever moves forward.
 */
class WebhookStatusTest extends TestCase
{
    use RefreshDatabase;

    private FakeAspGateway $asp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asp = new FakeAspGateway('status-secret');
        $this->app->instance(AspGateway::class, $this->asp);
    }

    public function test_acceptance_marks_document_delivered(): void
    {
        $document = $this->document('sent', 'msg-1');

        $this->report(['message_id' => 'msg-1', 'mls_code' => 'AP'])->assertOk();

        $document->refresh();
        $this->assertSame('delivered', $document->status);
        $this->assertSame('AP', $document->mls_code);
    }

    public function test_rejection_keeps_code_and_errors(): void
    {
        $document = $this->document('sent', 'msg-1');

        $this->report(['message_id' => 'msg-1', 'mls_code' => 'RE', 'errors' => ['BR-AE-10 invalid TRN']])
            ->assertOk();

        $document->refresh();
        $this->assertSame('rejected', $document->status);
        $this->assertSame('RE', $document->mls_code);
        $this->assertSame(['BR-AE-10 invalid TRN'], $document->errors);
    }

    /**
     * The authority's confirmation can overtake the buyer's acceptance; the
     * late acceptance then changes nothing, and is still acknowledged.
     */
    public function test_late_status_never_moves_backwards(): void
    {
        $document = $this->document('sent', 'msg-1');

        $this->report(['message_id' => 'msg-1', 'status' => 'reported'])->assertOk();
        $this->report(['message_id' => 'msg-1', 'status' => 'delivered'])->assertOk();
        $this->report(['message_id' => 'msg-1', 'status' => 'sent'])->assertOk();

        $this->assertSame('reported', $document->fresh()->status);
    }

    public function test_settled_document_stays_settled(): void
    {
        $rejected = $this->document('rejected', 'msg-1');
        $failed = $this->document('failed', 'msg-2');

        $this->report(['message_id' => 'msg-1', 'mls_code' => 'AP'])->assertOk();
        $this->report(['message_id' => 'msg-2', 'status' => 'delivered'])->assertOk();

        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('failed', $failed->fresh()->status);
    }

    public function test_transitions_only_move_forward(): void
    {
        $status = app(DocumentStatus::class);

        $this->assertTrue($status->allows('sent', 'delivered'));
        $this->assertTrue($status->allows('delivered', 'reported'));
        $this->assertTrue($status->allows('reported', 'rejected'));
        $this->assertFalse($status->allows('delivered', 'sent'));
        $this->assertFalse($status->allows('reported', 'delivered'));
        $this->assertFalse($status->allows('delivered', 'rejected'));
        $this->assertFalse($status->allows('failed', 'ready'));
        $this->assertFalse($status->allows('ready', 'sent'));
        $this->assertFalse($status->allows('received', 'sent'));
    }

    /**
     * Not ours - or the provider called back before its answer to the
     * submission reached us. Nothing is written; its retry will find it.
     */
    public function test_unknown_message_is_not_found(): void
    {
        $this->report(['message_id' => 'nobody', 'mls_code' => 'AP'])->assertNotFound();
    }

    public function test_unrecognised_status_changes_nothing(): void
    {
        $document = $this->document('sent', 'msg-1');

        $this->report(['message_id' => 'msg-1', 'status' => 'in_transit'])->assertOk();

        $this->assertSame('sent', $document->fresh()->status);
    }

    public function test_malformed_callback_is_refused(): void
    {
        $this->report(['status' => 'delivered'])->assertUnprocessable();
    }

    /**
     * The fallback for lost callbacks asks only about documents sent a while
     * ago and still unanswered.
     */
    public function test_poll_asks_about_quiet_documents(): void
    {
        $quiet = $this->document('sent', 'msg-old');
        $quiet->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $recent = $this->document('sent', 'msg-new');
        $this->asp->answer(new AspStatus('msg-old', 'delivered', 'AP'));

        $this->artisan('peppol:poll-status')->assertSuccessful();

        $this->assertSame(['msg-old'], $this->asp->queried);
        $this->assertSame('delivered', $quiet->fresh()->status);
        $this->assertSame('sent', $recent->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function report(array $body): TestResponse
    {
        $json = (string) json_encode($body);
        $timestamp = (string) time();

        return $this->call('POST', '/api/peppol/webhooks/status', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => (new WebhookSignature('status-secret'))->sign($timestamp, $json),
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        ], $json);
    }

    private function document(string $status, string $messageId): PeppolDocument
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
            'status' => $status,
            'asp_message_id' => $messageId,
        ]));
    }
}
