<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspUnavailableException;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspStatus;
use App\Domains\Compliance\Networks\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookPayload;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentStatus;
use App\Domains\Compliance\Networks\Peppol\Sending\PeppolSender;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * The report to the authority as a track of its own (UAE Electronic
 * Invoicing Guidelines v1.1, p10 steps 4 and 8, p41 item vi, p45): pending
 * from the moment the provider takes a document, confirmed or failed apart
 * from delivery, a failure asked again, and each outcome announced.
 */
class ReportingTrackTest extends TestCase
{
    use RefreshDatabase;

    private FakeAspGateway $asp;

    /** @var list<array{event: string, payload: array<string, mixed>}> */
    private array $announced = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->asp = new FakeAspGateway;
        $this->app->instance(AspGateway::class, $this->asp);

        $webhooks = Mockery::mock(WebhookService::class);
        $webhooks->shouldReceive('dispatch')->andReturnUsing(function (string $org, string $event, array $payload): void {
            $this->announced[] = ['event' => $event, 'payload' => $payload];
        });
        $this->app->instance(WebhookService::class, $webhooks);
    }

    public function test_taken_document_awaits_its_report(): void
    {
        $document = $this->document('ready');

        app(PeppolSender::class)->send((string) $document->id);

        $document->refresh();
        $this->assertSame(['sent', 'pending'], [$document->status, $document->reporting_status]);
        $this->assertSame(['e_invoice.sent'], array_column($this->announced, 'event'));
    }

    public function test_each_outcome_is_announced(): void
    {
        $document = $this->document('sent', 'msg-1');
        $status = app(DocumentStatus::class);

        $status->apply(new AspStatus('msg-1', 'delivered', 'AP'));
        $status->apply(new AspStatus('msg-1', 'report_failed', null, ['not acknowledged']));
        $status->apply(new AspStatus('msg-1', 'reported', null, [], 'TDD-9'));

        $this->assertSame(['e_invoice.delivered', 'e_invoice.report_failed', 'e_invoice.reported'], array_column($this->announced, 'event'));
        $last = $this->announced[2]['payload']['e_invoice'];
        $this->assertSame([$document->id, 'INV-7', 'delivered', 'reported', 'TDD-9'], [$last['id'], $last['number'], $last['status'], $last['reporting_status'], $last['authority_reference']]);
    }

    public function test_failed_report_is_logged_as_error(): void
    {
        $this->document('delivered', 'msg-1');
        Log::spy();

        app(DocumentStatus::class)->apply(new AspStatus('msg-1', 'report_failed'));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'report to the authority failed'))->once();
    }

    /** Asked again hourly: pending once the provider takes the ask, spaced when it does not. */
    public function test_failed_report_is_asked_again(): void
    {
        $due = $this->document('delivered', 'msg-due', 'report_failed', now()->subHours(2));
        $recent = $this->document('delivered', 'msg-recent', 'report_failed', now()->subMinutes(5));

        $this->artisan('peppol:retry-reports')->assertSuccessful();

        $this->assertSame(['msg-due'], $this->asp->reportRetried);
        $this->assertSame('pending', $due->fresh()->reporting_status);
        $this->assertSame('report_failed', $recent->fresh()->reporting_status);
    }

    public function test_unanswered_retry_waits_an_hour(): void
    {
        $document = $this->document('delivered', 'msg-1', 'report_failed', now()->subHours(2));
        $this->asp->failReportRetriesWith(new AspUnavailableException('down'));

        $this->artisan('peppol:retry-reports')->assertSuccessful();

        $document->refresh();
        $this->assertSame('report_failed', $document->reporting_status);
        $this->assertTrue($document->reporting_changed_at->isAfter(now()->subMinute()));
    }

    /** A lookup answers on both tracks, and each is applied. */
    public function test_poll_applies_both_tracks(): void
    {
        $document = $this->document('sent', 'msg-1');
        $document->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $this->asp->answer(...WebhookPayload::lookupFrom('msg-1', [
            'status' => 'delivered', 'mls_code' => 'AP', 'reporting_status' => 'reported', 'authority_reference' => 'TDD-2',
        ]));

        $this->artisan('peppol:poll-status')->assertSuccessful();

        $document->refresh();
        $this->assertSame(['delivered', 'reported', 'TDD-2'], [$document->status, $document->reporting_status, $document->authority_reference]);
    }

    /**
     * A reserved endpoint has no buyer's provider: taken, it is not
     * exchanged, waits only for its report, and is done once reported
     * (Electronic Invoicing Guidelines v1.1, p23, p26, p28).
     */
    public function test_report_only_endpoint_is_not_exchanged(): void
    {
        config(['peppol.countries.AE.report_only_endpoints' => ['0235:9900000098']]);
        $document = $this->document('ready');
        $document->forceFill(['receiver_endpoint' => '0235:9900000098'])->save();

        app(PeppolSender::class)->send((string) $document->id);
        app(DocumentStatus::class)->apply(new AspStatus('fake-'.$document->id, 'delivered', 'AP'));

        $document->refresh();
        $this->assertSame(['not_exchanged', 'pending', null], [$document->status, $document->reporting_status, $document->mls_code]);

        $document->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $this->artisan('peppol:poll-status')->assertSuccessful();
        $this->assertSame(['fake-'.$document->id], $this->asp->queried);

        app(DocumentStatus::class)->apply(new AspStatus('fake-'.$document->id, 'reported'));
        $document->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $this->artisan('peppol:poll-status')->assertSuccessful();
        $this->assertSame(['fake-'.$document->id], $this->asp->queried);
    }

    /**
     * A received document's report is made by the buyer's provider, which
     * forwards the authority's confirmation (Electronic Invoicing Guidelines
     * v1.1, p11 steps 9 and 11); a delivery status for it changes nothing.
     */
    public function test_received_document_records_its_report(): void
    {
        $received = $this->document('received', 'msg-in');
        $received->forceFill(['direction' => PeppolDocument::DIRECTION_IN, 'reporting_status' => null])->save();
        $status = app(DocumentStatus::class);

        $this->assertTrue($status->apply(new AspStatus('msg-in', 'reported', null, [], 'TDD-IN')));
        $this->assertFalse($status->apply(new AspStatus('msg-in', 'delivered', 'AP')));

        $received->refresh();
        $this->assertSame(['received', 'reported', 'TDD-IN'], [$received->status, $received->reporting_status, $received->authority_reference]);
        $this->assertSame('in', $this->announced[0]['payload']['e_invoice']['direction']);
    }

    /** Polled while either track is open, and not once both have answered. */
    public function test_poll_asks_until_reported(): void
    {
        $delivered = $this->document('delivered', 'msg-delivered');
        $done = $this->document('delivered', 'msg-done', 'reported');
        foreach ([$delivered, $done] as $document) {
            $document->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        }

        $this->artisan('peppol:poll-status')->assertSuccessful();

        $this->assertSame(['msg-delivered'], $this->asp->queried);
    }

    private function document(string $status, ?string $messageId = null, ?string $reporting = null, mixed $reportingChangedAt = null): PeppolDocument
    {
        $organization = Organization::create(['name' => 'Gulf Trading', 'country' => 'AE']);

        return PeppolDocument::acrossTenants()->create([
            'org_id' => $organization->id,
            'direction' => PeppolDocument::DIRECTION_OUT,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            'xml' => '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:ID>INV-7</cbc:ID></Invoice>',
            'status' => $status,
            'asp_message_id' => $messageId,
            'reporting_status' => $reporting ?? ($messageId !== null ? 'pending' : null),
            'reporting_changed_at' => $reportingChangedAt,
        ]);
    }
}
