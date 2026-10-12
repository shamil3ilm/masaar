<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A missed reporting deadline is noticed by something.
 *
 * Every other alert in this platform fires from the code path that failed,
 * which covers the failures that happen and not the ones that stop happening:
 * a worker that died, retries exhausted overnight, a submission left in flight
 * because the process was killed between the authority answering and the row
 * being written. Nothing raises an event, because nothing is running to raise
 * one.
 *
 * ZATCA gives a simplified invoice twenty-four hours from issuance. That is a
 * legal obligation, it passes in silence, and the only way to notice is to go
 * looking - so the sweep runs hourly and this holds it to finding the right
 * rows. Getting that wrong in either direction is costly: a miss is a breach
 * nobody hears about, and a false positive at 3am teaches people to mute it.
 */
class OverdueSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_an_overdue_report_is_found(): void
    {
        $this->submission(['state' => 'failed'], hoursAgo: 30);

        $this->artisan('fatoora:overdue-submissions')->assertFailed();
    }

    /**
     * Nothing to find means nothing to say, and a zero exit - otherwise an
     * hourly job cries wolf every hour.
     */
    public function test_a_quiet_hour_passes(): void
    {
        $this->submission(['state' => 'failed'], hoursAgo: 1);
        $this->submission(['state' => 'reported'], hoursAgo: 48);

        $this->artisan('fatoora:overdue-submissions')->assertSuccessful();
    }

    /**
     * A document that reached the authority is finished, however old it is.
     *
     * @return list<array{0: string}>
     */
    public static function settled(): array
    {
        return [['reported'], ['cleared'], ['warning']];
    }

    #[DataProvider('settled')]
    public function test_a_settled_document_is_not_overdue(string $state): void
    {
        $this->submission(['state' => $state], hoursAgo: 72);

        $this->artisan('fatoora:overdue-submissions')->assertSuccessful();
    }

    /**
     * A standard invoice is cleared before it is issued, so an unsubmitted one
     * has not reached a customer and no clock is running on it. Including them
     * would fill the alert with rows that are merely waiting.
     */
    public function test_clearance_has_no_deadline(): void
    {
        $this->submission(['state' => 'failed', 'submission_type' => 'clearance'], hoursAgo: 72);

        $this->artisan('fatoora:overdue-submissions')->assertSuccessful();
    }

    /**
     * At risk is reported but does not fail the run: the deadline has not
     * passed, and a non-zero exit every hour for something still recoverable
     * is how an alert gets ignored.
     */
    public function test_at_risk_is_reported_without_failing(): void
    {
        $this->submission(['state' => 'queued'], hoursAgo: 20);

        $this->artisan('fatoora:overdue-submissions')->assertSuccessful();
    }

    /**
     * And the alert actually carries what is needed to act: how many, and
     * which invoices.
     */
    public function test_the_alert_names_the_invoices(): void
    {
        $submission = $this->submission(['state' => 'failed'], hoursAgo: 30);

        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            if ($message->level === 'critical') {
                $captured[] = $message->context;
            }
        });

        $this->artisan('fatoora:overdue-submissions', ['--alert' => true])->assertFailed();

        $this->assertNotEmpty($captured, 'Nothing was raised at critical, so no alert reaches the channel.');
        $this->assertSame(1, $captured[0]['overdue']);
        $this->assertContains($submission->invoice_id, $captured[0]['invoice_ids']);
    }

    /**
     * Without --alert nothing is raised, so a human running it to look does
     * not page the on-call.
     */
    public function test_looking_does_not_alert(): void
    {
        $this->submission(['state' => 'failed'], hoursAgo: 30);

        $raised = 0;
        Log::listen(function ($message) use (&$raised): void {
            if ($message->level === 'critical') {
                $raised++;
            }
        });

        $this->artisan('fatoora:overdue-submissions')->assertFailed();

        $this->assertSame(0, $raised, 'Running the report without --alert still paged someone.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function submission(array $attributes, int $hoursAgo): InvoiceSubmission
    {
        $invoice = Invoice::create([
            'org_id' => $this->org->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'status' => 'issued',
            'issue_date' => Carbon::now()->subHours($hoursAgo)->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in',
        ]);

        $submission = InvoiceSubmission::create(array_merge([
            'invoice_id' => $invoice->id,
            'org_id' => $this->org->id,
            'submission_type' => 'reporting',
            'retry_count' => 0,
        ], $attributes));

        // created_at is what the age is measured from, and the model sets it.
        $submission->forceFill(['created_at' => Carbon::now()->subHours($hoursAgo)])->saveQuietly();

        return $submission->refresh();
    }
}
