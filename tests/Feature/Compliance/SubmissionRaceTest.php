<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\DuplicateDetector;
use App\Domains\Compliance\Fatoora\Services\SubmissionTracker;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * An invoice reaches ZATCA once, whatever happens around the call.
 *
 * Two hazards are covered. A local write that fails after the authority has
 * accepted a document must not turn that acceptance into a failure, because a
 * failure is retried and the retry sends the document again. And two callers
 * holding the same invoice — a duplicate job, a second request under another
 * idempotency key, a double-clicked retry — must not both send it.
 *
 * Races are reproduced sequentially: the competing caller is run between the
 * check and the write it would slip through.
 */
class SubmissionRaceTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $credentials = $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
        );

        Http::fake([
            '*/invoices/*' => Http::response([
                'clearanceStatus' => 'CLEARED',
                'validationResults' => ['status' => 'PASS'],
            ]),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    /**
     * The usage counter failing after clearance leaves the submission where it
     * was, not failed, and a second run of the job does not send it again.
     */
    public function test_job_never_fails_an_accepted_document(): void
    {
        $this->usageStoreIsDown();
        $submission = $this->submission('queued');

        $this->runJob($submission);
        $this->runJob($submission);

        $this->assertSame('submitted', $submission->fresh()->state);
        $this->assertNull($submission->fresh()->next_retry_at);
        $this->assertSame(1, $this->zatcaCalls());
    }

    /**
     * A second job for a submission another worker is already sending leaves
     * it alone.
     */
    public function test_job_skips_a_submission_in_flight(): void
    {
        $submission = $this->submission('submitted');

        $this->runJob($submission);

        $this->assertSame(0, $this->zatcaCalls());
    }

    public function test_sync_never_fails_an_accepted_document(): void
    {
        $this->usageStoreIsDown();

        $result = app(SubmissionTracker::class)->submit($this->issued('INV-1'));

        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::first());

        $this->assertTrue($result['success']);
        $this->assertSame('submitted', $submission->state);
        $this->assertNull($submission->next_retry_at);
    }

    /**
     * Two retries of one failed submission, each holding a copy read before
     * the other ran, send the document once.
     */
    public function test_concurrent_retries_send_once(): void
    {
        $submission = $this->submission('failed');
        $stale = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::find($submission->id));

        app(SubmissionTracker::class)->retry($submission);

        try {
            app(SubmissionTracker::class)->retry($stale);
            $this->fail('A retry of a cleared submission was accepted.');
        } catch (FatooraException) {
            $this->assertSame(1, $this->zatcaCalls());
        }
    }

    /**
     * A request under a new idempotency key — the default key changes at
     * midnight — arrives while another request for the same invoice is being
     * sent. The competitor is created after the guard has looked and before
     * the submission row is written.
     */
    public function test_second_key_waits_for_the_first(): void
    {
        $invoice = $this->issued('INV-1');

        $detector = \Mockery::mock(DuplicateDetector::class);
        $detector->shouldReceive('check')->andReturnUsing(function () use ($invoice): array {
            $this->submission('submitted', $invoice);

            return ['is_duplicate' => false, 'duplicates' => []];
        });
        $this->app->instance(DuplicateDetector::class, $detector);

        try {
            app(SubmissionTracker::class)->submit($invoice, 'second-key');
            $this->fail('A second request sent an invoice already being sent.');
        } catch (FatooraException $e) {
            $this->assertSame(ErrorCode::IDEM_PROCESSING_IN_PROGRESS, $e->getErrorCode());
            $this->assertSame(0, $this->zatcaCalls());
        }
    }

    private function usageStoreIsDown(): void
    {
        $usage = \Mockery::mock(UsageMeteringService::class);
        $usage->shouldReceive('recordSubmissionOutcome')->andThrow(new \RuntimeException('usage store down'));
        $this->app->instance(UsageMeteringService::class, $usage);
    }

    private function runJob(InvoiceSubmission $submission): void
    {
        $this->app->call([new ProcessFatooraSubmission($submission), 'handle']);
    }

    private function zatcaCalls(): int
    {
        return count(Http::recorded(fn (Request $request) => str_contains($request->url(), '/invoices/')));
    }

    private function submission(string $state, ?Invoice $invoice = null): InvoiceSubmission
    {
        $invoice ??= $this->issued('INV-1');

        return InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id,
            'org_id' => $this->organization->id,
            'state' => $state,
            'submission_type' => 'clearance',
            'submission_mode' => 'sync',
            'last_error_code' => $state === 'failed' ? ErrorCode::NET_TIMEOUT->value : null,
        ]));
    }

    private function issued(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '399999999900003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        app(Submitter::class)->generate($invoice, $this->organization);

        return $invoice->fresh(['lines']);
    }
}
