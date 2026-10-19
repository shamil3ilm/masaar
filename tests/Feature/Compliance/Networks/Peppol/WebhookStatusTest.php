<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspStatus;
use App\Domains\Compliance\Networks\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentStatus;
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

        $this->report(['message_id' => 'msg-1', 'mls_code' => 'RE', 'errors' => [
            ['rule_id' => 'BR-AE-10', 'severity' => 'fatal', 'field' => 'cbc:CompanyID', 'message' => 'invalid TRN'],
            'BV: Business rule violation',
        ]])->assertOk();

        $document->refresh();
        $this->assertSame('rejected', $document->status);
        $this->assertSame('RE', $document->mls_code);
        $this->assertSame([
            ['code' => 'BR-AE-10', 'severity' => 'fatal', 'field' => 'cbc:CompanyID', 'message' => 'invalid TRN'],
            ['code' => 'BV', 'severity' => 'error', 'field' => null, 'message' => 'Business rule violation'],
        ], $document->errors);
    }

    /**
     * The authority's confirmation can overtake the buyer's acceptance. The
     * two are separate tracks (UAE Electronic Invoicing Guidelines v1.1,
     * p10-11), so the late acceptance is still recorded, and a late 'sent'
     * moves nothing back.
     */
    public function test_early_report_does_not_hide_delivery(): void
    {
        $document = $this->document('sent', 'msg-1');

        $this->report(['message_id' => 'msg-1', 'status' => 'reported', 'authority_reference' => 'TDD-1'])->assertOk();
        $this->report(['message_id' => 'msg-1', 'status' => 'delivered'])->assertOk();
        $this->report(['message_id' => 'msg-1', 'status' => 'sent'])->assertOk();

        $document->refresh();
        $this->assertSame(['delivered', 'reported', 'TDD-1'], [$document->status, $document->reporting_status, $document->authority_reference]);
    }

    /** A failed report has its own status, and leaves delivery as it was. */
    public function test_failed_report_has_its_own_status(): void
    {
        $document = $this->document('delivered', 'msg-1');

        $this->report(['message_id' => 'msg-1', 'status' => 'report_failed', 'errors' => ['The authority did not acknowledge it']])->assertOk();

        $document->refresh();
        $this->assertSame(['delivered', 'report_failed'], [$document->status, $document->reporting_status]);
        $this->assertSame([['code' => null, 'severity' => 'error', 'field' => null, 'message' => 'The authority did not acknowledge it']], $document->reporting_errors);
        $this->assertNull($document->errors);
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
        $this->assertTrue($status->allows('sent', 'rejected'));
        $this->assertFalse($status->allows('delivered', 'sent'));
        $this->assertFalse($status->allows('delivered', 'rejected'));
        // Only back to ready, which no provider status maps to: the one way
        // out of failed is an operator's peppol:resend.
        $this->assertTrue($status->allows('failed', 'ready'));
        $this->assertFalse($status->allows('failed', 'sent'));
        $this->assertFalse($status->allows('ready', 'sent'));
        $this->assertFalse($status->allows('received', 'sent'));

        // The report: pending until the authority answers, a failure asked again.
        $this->assertTrue($status->allowsReporting('pending', 'reported'));
        $this->assertTrue($status->allowsReporting('pending', 'report_failed'));
        $this->assertTrue($status->allowsReporting('report_failed', 'pending'));
        $this->assertTrue($status->allowsReporting('report_failed', 'reported'));
        $this->assertFalse($status->allowsReporting('reported', 'report_failed'));
        $this->assertFalse($status->allowsReporting('reported', 'pending'));
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

    /** Status polling for documents already sent carries on with the UAE switched off. */
    public function test_poll_continues_while_switched_off(): void
    {
        $quiet = $this->document('sent', 'msg-old');
        $quiet->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $this->asp->answer(new AspStatus('msg-old', 'delivered', 'AP'));
        app(JurisdictionAvailability::class)->set('AE', false);

        $this->artisan('peppol:poll-status')->assertSuccessful();

        $this->assertSame('delivered', $quiet->fresh()->status);
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
            'reporting_status' => in_array($status, ['sent', 'delivered', 'rejected'], true) ? 'pending' : null,
        ]));
    }
}
