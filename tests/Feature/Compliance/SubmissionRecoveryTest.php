<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\SubmissionGuard;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A submission left in flight is found and sent on, not left to block.
 *
 * A submission is in flight while it is queued, claimed (pending_submission)
 * or sent and awaiting the answer (submitted). A worker that dies, a job the
 * queue loses, a process killed mid-request - each leaves the row in one of
 * those states with nothing coming back for it. Nothing did come back for
 * it: the invoice could never be filed again, because a second submission is
 * refused while one is in flight, and enough of them counted against the
 * concurrent limit for ever and shut the whole tenant out of filing.
 *
 * Each state is recovered according to what it can mean. Queued and claimed
 * have sent nothing, so they are sent. Submitted may have reached the
 * authority: a report is resent, because ZATCA has been observed accepting the
 * same report twice; a clearance is not, because clearing a document twice is
 * not something to find out about in production, and it is reported for
 * reconciliation instead.
 */
class SubmissionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['fatoora.recovery.stale_minutes' => 15]);

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
    }

    public function test_a_lost_queued_job_is_resent(): void
    {
        $submission = $this->inFlight('queued', 'reporting', minutesAgo: 30);

        $this->artisan('fatoora:recover-submissions')->assertSuccessful();

        Queue::assertPushed(ProcessFatooraSubmission::class, fn ($job) => $job->submission->id === $submission->id);
    }

    /**
     * A job waiting behind a backlog is not lost. Recovered once, the row is
     * re-stamped, so the next run does not queue it again.
     */
    public function test_a_resent_job_is_not_requeued(): void
    {
        $submission = $this->inFlight('queued', 'reporting', minutesAgo: 30);

        $this->artisan('fatoora:recover-submissions')->assertSuccessful();
        $this->artisan('fatoora:recover-submissions')->assertSuccessful();

        Queue::assertPushed(ProcessFatooraSubmission::class, 1);
        $this->assertSame('queued', $submission->fresh()->state);
    }

    /**
     * The queue refusing the job leaves the row as it was, stale, so the next
     * run tries again - not marked failed with no job, which nothing resends.
     */
    public function test_a_refused_push_stays_recoverable(): void
    {
        $submission = $this->inFlight('pending_submission', 'reporting', minutesAgo: 30);

        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));

        $this->artisan('fatoora:recover-submissions')->assertFailed();

        $this->assertSame('pending_submission', $submission->fresh()->state);
        $this->assertTrue(InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()->stale()->whereKey($submission->id)->exists()));
    }

    public function test_a_recent_one_is_left_alone(): void
    {
        $submission = $this->inFlight('submitted', 'reporting', minutesAgo: 2);

        $this->artisan('fatoora:recover-submissions')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('submitted', $submission->fresh()->state);
    }

    /**
     * Claimed and never sent: the transition to submitted comes before the
     * request, so a row still pending_submission sent nothing.
     */
    public function test_a_crashed_claim_is_sent(): void
    {
        $submission = $this->inFlight('pending_submission', 'clearance', minutesAgo: 30);

        $this->artisan('fatoora:recover-submissions')->assertSuccessful();

        $this->assertSame('failed', $submission->fresh()->state);
        Queue::assertPushed(ProcessFatooraSubmission::class);
    }

    public function test_an_unanswered_report_is_resent(): void
    {
        $submission = $this->inFlight('submitted', 'reporting', minutesAgo: 30);

        $this->artisan('fatoora:recover-submissions')->assertSuccessful();

        $this->assertSame('failed', $submission->fresh()->state);
        Queue::assertPushed(ProcessFatooraSubmission::class);
    }

    public function test_an_unanswered_clearance_is_reported(): void
    {
        $submission = $this->inFlight('submitted', 'clearance', minutesAgo: 30);

        $this->artisan('fatoora:recover-submissions')
            ->expectsOutputToContain('reconcil')
            ->assertSuccessful();

        $this->assertSame('submitted', $submission->fresh()->state);
        Queue::assertNothingPushed();
    }

    /**
     * Stale rows are not concurrent submissions, so they cannot fill the
     * tenant's concurrent allowance and stop everything else.
     */
    public function test_stale_rows_do_not_lock_out(): void
    {
        config(['fatoora.rate_limits.max_concurrent' => 2]);

        $this->inFlight('submitted', 'clearance', minutesAgo: 60);
        $this->inFlight('submitted', 'clearance', minutesAgo: 60);

        $check = new \ReflectionMethod(SubmissionGuard::class, 'checkConcurrentSubmissions');

        $check->invoke(app(SubmissionGuard::class), $this->acme);

        $this->addToAssertionCount(1);
    }

    private function inFlight(string $state, string $type, int $minutesAgo): InvoiceSubmission
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-'.uniqid(),
            'type' => $type === 'clearance' ? 'standard' : 'simplified',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            'icv' => random_int(1, 1000000),
            'hash' => base64_encode(random_bytes(32)),
        ]));

        $submission = (new InvoiceSubmission)->forceFill([
            'invoice_id' => $invoice->id,
            'org_id' => $this->acme->id,
            'state' => $state,
            'submission_type' => $type,
            'submission_mode' => 'async',
            'state_changed_at' => now()->subMinutes($minutesAgo),
        ]);
        $submission->save();

        return $submission;
    }
}
