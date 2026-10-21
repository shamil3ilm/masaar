<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\IllegalSubmissionMoveException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionLedger;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionState;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A submission moves only along its table (SubmissionState), and every real
 * filing flow stays on it.
 *
 * The table is written out here a second time, by hand, from the flows the
 * code has: the enum changing without this file changing is the point of
 * failure, not a formality.
 */
class SubmissionStateTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    /** @var array<string, list<string>> */
    private const TABLE = [
        'draft' => ['queued', 'pending_submission', 'failed', 'cancelled'],
        'queued' => ['queued', 'pending_submission', 'cancelled', 'submitted', 'cleared', 'reported', 'warning', 'rejected', 'failed'],
        'pending_submission' => ['submitted', 'cleared', 'reported', 'warning', 'rejected', 'failed'],
        'submitted' => ['submitted', 'cleared', 'reported', 'warning', 'rejected', 'failed'],
        'failed' => ['failed', 'pending_submission', 'queued'],
        'rejected' => ['pending_submission', 'queued'],
        'cleared' => [],
        'reported' => [],
        'warning' => [],
        'cancelled' => [],
    ];

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds', 'fatoora.retry_attempts' => 1]);
        Queue::fake();

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);

        $this->mock(Connectivity::class, fn ($mock) => $mock->shouldReceive('shouldUseOfflineMode')->andReturn(false));
    }

    /** @return array<string, array{string, string}> */
    public static function allowedMoves(): array
    {
        $moves = [];
        foreach (self::TABLE as $from => $targets) {
            foreach ($targets as $to) {
                $moves["{$from} to {$to}"] = [$from, $to];
            }
        }

        return $moves;
    }

    /** @return array<string, array{string, string}> */
    public static function refusedMoves(): array
    {
        $moves = [];
        foreach (self::TABLE as $from => $targets) {
            foreach (array_keys(self::TABLE) as $to) {
                if (! in_array($to, $targets, true)) {
                    $moves["{$from} to {$to}"] = [$from, $to];
                }
            }
        }

        return $moves;
    }

    public function test_the_enum_matches_the_table(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::TABLE), array_map(fn (SubmissionState $s) => $s->value, SubmissionState::cases()));

        foreach (SubmissionState::cases() as $state) {
            $this->assertEqualsCanonicalizing(self::TABLE[$state->value], array_map(fn (SubmissionState $s) => $s->value, $state->next()), $state->value);
        }
    }

    #[DataProvider('allowedMoves')]
    public function test_every_allowed_move_succeeds(string $from, string $to): void
    {
        $submission = $this->submission($from);

        app(SubmissionLedger::class)->transition($submission, $to, 'test');

        $this->assertSame($to, $submission->fresh()->state);
        $this->assertSame($from, $submission->fresh()->previous_state);
        $this->assertTrue(DB::table('submission_state_logs')->where('submission_id', $submission->id)
            ->where('from_state', $from)->where('to_state', $to)->exists());
    }

    #[DataProvider('refusedMoves')]
    public function test_every_disallowed_move_is_refused(string $from, string $to): void
    {
        $submission = $this->submission($from);
        Log::spy();

        try {
            app(SubmissionLedger::class)->transition($submission, $to, 'test');
            $this->fail("{$from} -> {$to} was allowed");
        } catch (IllegalSubmissionMoveException $e) {
            $this->assertStringContainsString("'{$from}'", $e->getMessage());
            $this->assertStringContainsString("'{$to}'", $e->getMessage());
        }

        $this->assertSame($from, $submission->fresh()->state);
        $this->assertFalse(DB::table('submission_state_logs')->where('submission_id', $submission->id)->exists());
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['submission_id'] === $submission->id
            && $context['from'] === $from && $context['to'] === $to)->once();
    }

    public function test_a_direct_write_is_refused(): void
    {
        $submission = $this->submission('failed');

        $this->expectException(IllegalSubmissionMoveException::class);

        $submission->update(['state' => 'cleared']);
    }

    public function test_opening_in_late_state_refused(): void
    {
        $this->expectException(IllegalSubmissionMoveException::class);

        app(SubmissionLedger::class)->open([
            'invoice_id' => $this->draft('OPEN-1')->id, 'org_id' => $this->acme->id, 'state' => 'cleared',
            'submission_type' => 'reporting', 'submission_mode' => 'sync',
        ], 'test');
    }

    public function test_reporting_flow_stays_legal(): void
    {
        Http::fake(['*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);

        $result = app(SubmissionTracker::class)->submit($this->issued('R-1'));

        $this->assertSame('reported', $result['state']);
        $this->assertHistory($result['submission_id'], [[null, 'pending_submission'], ['pending_submission', 'submitted'], ['submitted', 'reported']]);
    }

    /** A-M1: the direct path files with a row and a history, like every other. */
    public function test_direct_submit_keeps_a_row(): void
    {
        Http::fake(['*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);
        $invoice = $this->issued('D-1');

        app(Submitter::class)->submit($invoice, $this->acme);

        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()->where('invoice_id', $invoice->id)->sole());
        $this->assertSame('reported', $submission->state);
        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
        $this->assertHistory($submission->id, [[null, 'pending_submission'], ['pending_submission', 'submitted'], ['submitted', 'reported']]);
    }

    /** And a clearance that may have reached ZATCA is held there too. */
    public function test_direct_clearance_hold_is_kept(): void
    {
        Http::fake(['*/invoices/clearance/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds')]);
        $invoice = $this->issued('D-2', standard: true);

        app(Submitter::class)->submit($invoice, $this->acme);

        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()->where('invoice_id', $invoice->id)->sole());
        $this->assertSame('submitted', $submission->state);
        $this->assertFalse($submission->isResendable());
    }

    public function test_clearance_flow_stays_legal(): void
    {
        Http::fake(['*/invoices/clearance/*' => Http::response(['clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS'], 'clearedInvoice' => base64_encode('<Invoice/>')])]);

        $result = app(SubmissionTracker::class)->submit($this->issued('C-1', standard: true));

        $this->assertSame('cleared', $result['state']);
        $this->assertHistory($result['submission_id'], [[null, 'pending_submission'], ['pending_submission', 'submitted'], ['submitted', 'cleared']]);
    }

    public function test_warnings_flow_stays_legal(): void
    {
        Http::fake(['*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => [
            'status' => 'WARNING', 'warningMessages' => [['code' => 'BR-KSA-F-13', 'message' => 'check the id']],
        ]], 202)]);

        $result = app(SubmissionTracker::class)->submit($this->issued('W-1'));

        $this->assertSame('warning', $result['state']);
        $this->assertHistory($result['submission_id'], [[null, 'pending_submission'], ['pending_submission', 'submitted'], ['submitted', 'warning']]);
    }

    public function test_rejected_verdict_stays_legal(): void
    {
        Http::fake(['*/invoices/reporting/*' => Http::response([
            'validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-08', 'message' => 'seller id']]],
            'reportingStatus' => 'NOT_REPORTED',
        ], 400)]);
        $invoice = $this->issued('X-1');

        $result = app(SubmissionTracker::class)->submit($invoice);

        $this->assertSame('rejected', $result['state']);
        $this->assertSame(InvoiceStatus::Rejected, $invoice->fresh()->status);
        $this->assertHistory($result['submission_id'], [[null, 'pending_submission'], ['pending_submission', 'submitted'], ['submitted', 'rejected']]);
    }

    public function test_held_answer_stays_legal(): void
    {
        Http::fake(['*/invoices/reporting/*' => Http::response([], 413)]);
        $invoice = $this->issued('H-1');

        $result = app(SubmissionTracker::class)->submit($invoice);

        $this->assertSame('failed', $result['state']);
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertHistory($result['submission_id'], [[null, 'pending_submission'], ['pending_submission', 'submitted'], ['submitted', 'failed']]);
    }

    /**
     * Refused credentials pause the device; the next attempt fails before
     * anything is sent, through the ledger and from where it really was.
     */
    public function test_paused_device_stays_legal(): void
    {
        Http::fake(['*/invoices/reporting/*' => Http::response(['message' => 'Unauthorized'], 401)]);
        $first = $this->queued($this->issued('P-1'));
        $this->app->call([new ProcessFatooraSubmission($first), 'handle']);

        $this->assertNotNull(app(Tills::class)->find($this->acme->id, $this->acme->id)?->filing_paused_at, 'the device was not paused');
        $this->assertHistory($first->id, [[null, 'queued'], ['queued', 'pending_submission'], ['pending_submission', 'submitted'], ['submitted', 'failed']]);

        $second = $this->queued($this->issued('P-2'));
        try {
            $this->app->call([new ProcessFatooraSubmission($second), 'handle']);
            $this->fail('a paused device filed');
        } catch (\Throwable) {
        }

        $this->assertSame('failed', $second->fresh()->state);
        $this->assertHistory($second->id, [[null, 'queued'], ['queued', 'pending_submission'], ['pending_submission', 'failed']]);
    }

    /** The queue giving up logs the move it made, not "failed to failed" from a pending row. */
    public function test_job_failure_logs_real_origin(): void
    {
        $submission = $this->queued($this->issued('F-1'));

        (new ProcessFatooraSubmission($submission))->failed(new \RuntimeException('worker died'));

        $this->assertSame('failed', $submission->fresh()->state);
        $this->assertHistory($submission->id, [[null, 'queued'], ['queued', 'failed']]);
    }

    public function test_offline_filing_stays_legal(): void
    {
        Http::fake(['*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);
        $invoice = $this->issued('O-1');

        $submission = app(SubmissionTracker::class)->recordOffline($invoice);
        app(OfflineQueue::class)->queue($invoice, (string) $invoice->signed_xml, (string) $invoice->hash, (string) $invoice->qr_code);
        $this->artisan('fatoora:process-offline', ['--organization' => $this->acme->id, '--force' => true]);

        $this->assertSame('reported', $submission->fresh()->state);
        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
        $this->assertHistory($submission->id, [[null, 'queued'], ['queued', 'reported']]);
    }

    /**
     * A clearance that may have reached ZATCA is held for reconciliation:
     * it stays submitted, which nothing sends from, and the job does not
     * throw, so the queue does not send it again.
     */
    public function test_reconciliation_hold_is_not_resent(): void
    {
        Http::fake(['*/invoices/clearance/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds')]);
        $submission = $this->queued($this->issued('Q-1', standard: true), 'clearance');

        Log::spy();

        // Returns, rather than throwing for the queue to retry.
        $this->app->call([new ProcessFatooraSubmission($submission), 'handle']);

        $this->assertSame('submitted', $submission->fresh()->state);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'reconcile'))->once();
        $this->assertHistory($submission->id, [[null, 'queued'], ['queued', 'pending_submission'], ['pending_submission', 'submitted'], ['submitted', 'submitted']]);
    }

    public function test_recovery_stays_legal(): void
    {
        $submission = $this->queued($this->issued('S-1'));
        app(SubmissionLedger::class)->transition($submission, 'pending_submission', 'test');
        InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()->whereKey($submission->id)
            ->toBase()->update(['state_changed_at' => now()->subHour()]));

        $this->artisan('fatoora:recover-submissions')->assertSuccessful();

        $this->assertSame('failed', $submission->fresh()->state);
        $this->assertHistory($submission->id, [[null, 'queued'], ['queued', 'pending_submission'], ['pending_submission', 'failed']]);
    }

    /**
     * @param  list<array{?string, string}>  $expected
     */
    private function assertHistory(string $submissionId, array $expected): void
    {
        $rows = DB::table('submission_state_logs')->where('submission_id', $submissionId)
            ->orderBy('created_at')->orderBy('id')->get(['from_state', 'to_state']);

        foreach ($rows as $row) {
            $this->assertTrue(
                $row->from_state === null
                    ? in_array(SubmissionState::from($row->to_state), SubmissionState::initial(), true)
                    : SubmissionState::from($row->from_state)->allows(SubmissionState::from($row->to_state)),
                "logged an illegal move {$row->from_state} -> {$row->to_state}"
            );
        }

        $actual = $rows->map(fn ($row) => [$row->from_state, $row->to_state])->all();
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    private function submission(string $state): InvoiceSubmission
    {
        $invoice = $this->draft('T-'.uniqid());

        return InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id, 'org_id' => $this->acme->id, 'state' => $state,
            'submission_type' => 'reporting', 'submission_mode' => 'async',
        ]));
    }

    private function queued(Invoice $invoice, string $type = 'reporting'): InvoiceSubmission
    {
        return app(SubmissionLedger::class)->open([
            'invoice_id' => $invoice->id, 'org_id' => $this->acme->id, 'state' => 'queued',
            'submission_type' => $type, 'submission_mode' => 'async',
        ], 'test');
    }

    private function draft(string $number, bool $standard = false): Invoice
    {
        $buyer = $standard ? [
            'buyer_name' => 'Beta Industries', 'buyer_name_ar' => 'شركة بيتا', 'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
        ] : [];

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id, 'invoice_number' => $number, 'type' => $standard ? 'standard' : 'simplified',
            'document_type' => 'invoice', 'status' => 'draft', 'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00', ...$buyer,
        ]));

        $invoice->lines()->create([
            'description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    private function issued(string $number, bool $standard = false): Invoice
    {
        $invoice = $this->draft($number, $standard);
        app(Submitter::class)->generate($invoice, $this->acme);

        return $invoice->fresh(['lines']);
    }
}
