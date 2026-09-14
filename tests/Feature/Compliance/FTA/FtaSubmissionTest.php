<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\FTA;

use App\Domains\Compliance\FTA\Enums\FtaStatus;
use App\Domains\Compliance\FTA\Exceptions\FtaException;
use App\Domains\Compliance\FTA\Models\FtaSubmission;
use App\Domains\Compliance\FTA\Services\FtaService;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What the UAE FTA answered, and what the submission records because of it.
 *
 * The authority is faked at the HTTP boundary. The line under test is between
 * a verdict and a failure to reach one: a rejection is the authority's answer,
 * while a server error, a throttle or an unreachable host leaves the document
 * unjudged and schedules another attempt.
 */
class FtaSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const XML = '<Invoice>AE-1</Invoice>';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fta.environment' => 'sandbox',
            'fta.endpoints.sandbox' => 'https://fta.test/api/v1',
            'fta.api_key' => 'test-key',
        ]);
    }

    public function test_an_acceptance_is_recorded(): void
    {
        Http::fake(['fta.test/*' => Http::response([
            'status' => 'accepted',
            'submissionId' => 'FTA-1',
            'validationStatus' => 'PASS',
        ])]);

        $submission = $this->resubmit();

        $this->assertSame(FtaStatus::Accepted, $submission->status);
        $this->assertSame('FTA-1', $submission->reference);
        $this->assertNotNull($submission->accepted_at);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://fta.test/api/v1/invoices'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->body() === self::XML);
    }

    public function test_a_refusal_is_a_rejection(): void
    {
        Http::fake(['fta.test/*' => Http::response(['errors' => ['Invalid TRN']], 422)]);

        $submission = $this->resubmit();

        $this->assertSame(FtaStatus::Rejected, $submission->status);
        $this->assertSame(['Invalid TRN'], $submission->errors);
        $this->assertNull($submission->next_retry_at);
    }

    /**
     * A 503 was read as a body without a status, which became a rejection: the
     * document was closed as refused when the authority had not looked at it.
     */
    public function test_a_server_error_is_retried_later(): void
    {
        Http::fake(['fta.test/*' => Http::response('Service Unavailable', 503)]);

        $submission = $this->resubmit();

        $this->assertSame(FtaStatus::Failed, $submission->status);
        $this->assertStringContainsString('503', (string) $submission->last_error);
        $this->assertNotNull($submission->next_retry_at);
    }

    public function test_throttling_is_not_a_rejection(): void
    {
        Http::fake(['fta.test/*' => Http::response(['errors' => ['Too many requests']], 429)]);

        $this->assertSame(FtaStatus::Failed, $this->resubmit()->status);
    }

    public function test_an_unreachable_authority_fails_softly(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $submission = $this->resubmit();

        $this->assertSame(FtaStatus::Failed, $submission->status);
        $this->assertStringContainsString('timed out', (string) $submission->last_error);
    }

    /**
     * The reference is the authority's, and it goes into a URL path, so it is
     * encoded rather than trusted to be path-safe.
     */
    public function test_a_status_check_records_the_verdict(): void
    {
        Http::fake(['fta.test/*' => Http::response(['status' => 'accepted'])]);

        $submission = app(FtaService::class)->checkStatus(
            $this->submission(FtaStatus::PendingReview, reference: 'FTA/1?x')
        );

        $this->assertSame(FtaStatus::Accepted, $submission->status);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://fta.test/api/v1/submissions/FTA%2F1%3Fx/status');
    }

    public function test_a_failed_status_check_changes_nothing(): void
    {
        Http::fake(['fta.test/*' => Http::response('', 500)]);

        $submission = app(FtaService::class)->checkStatus(
            $this->submission(FtaStatus::PendingReview, reference: 'FTA-1')
        );

        $this->assertSame(FtaStatus::PendingReview, $submission->fresh()->status);
    }

    public function test_an_unknown_environment_uses_sandbox(): void
    {
        config(['fta.environment' => 'staging']);

        Http::fake(['fta.test/*' => Http::response(['status' => 'accepted'])]);

        $this->resubmit();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://fta.test/api/v1/'));
    }

    /**
     * Two retries of one failed submission, each holding a copy read before
     * the other ran, submit once and count once.
     */
    public function test_concurrent_retries_submit_once(): void
    {
        Http::fake(['fta.test/*' => Http::response(['status' => 'accepted', 'submissionId' => 'FTA-1'])]);

        $submission = $this->submission(FtaStatus::Failed);
        $stale = FtaSubmission::withoutTenantScope(fn () => FtaSubmission::find($submission->id));

        app(FtaService::class)->retry($submission);

        try {
            app(FtaService::class)->retry($stale);
            $this->fail('A retry of an accepted submission was sent.');
        } catch (FtaException) {
            Http::assertSentCount(1);
            $this->assertSame(1, $submission->fresh()->retry_count);
        }
    }

    private function resubmit(): FtaSubmission
    {
        return app(FtaService::class)->retry($this->submission(FtaStatus::Failed));
    }

    private function submission(FtaStatus $status, ?string $reference = null): FtaSubmission
    {
        $organization = Organization::create([
            'name' => 'Acme FZE',
            'country' => 'AE',
            'vat_number' => '100000000000003',
        ]);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'AE-1',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => '2026-03-01',
            'currency' => 'AED',
            'buyer_name' => 'Buyer LLC',
            'subtotal' => '100.00',
            'tax_amount' => '5.00',
            'total' => '105.00',
        ]));

        return FtaSubmission::withoutTenantScope(fn () => FtaSubmission::create([
            'invoice_id' => $invoice->id,
            'org_id' => $organization->id,
            'status' => $status,
            'reference' => $reference,
            'document_type' => '380',
            'invoice_xml' => self::XML,
            'retry_count' => 0,
            'max_retries' => 5,
        ]));
    }
}
