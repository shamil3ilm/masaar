<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineFallback;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Models\OfflineItem;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * What happens to an invoice when ZATCA cannot be reached.
 *
 * This is the resilience path — a till keeps selling during an outage and the
 * documents are submitted later — and it had no test at all. Two defects were
 * sitting in it.
 *
 * shouldFallbackToOffline() listed three ErrorCode cases that do not exist. An
 * undefined enum case is a fatal Error rather than a null, so the method died
 * the moment a FatooraException reached it: the invoice was neither submitted
 * nor queued, and the mechanism failed in exactly the outage it exists for.
 *
 * queueForOffline() read $complianceData['qrCode'] where DocumentBuilder
 * returns 'qr_code', so the QR was null on the invoice and in the queue. For a
 * B2C sale that QR is what gets printed on the receipt.
 */
class OfflineFallbackTest extends TestCase
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
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']
        );
    }

    /**
     * The decision that matters: ZATCA unreachable means queued, not lost.
     */
    public function test_outage_queues_the_invoice(): void
    {
        $this->submissionFails(ErrorCode::NET_TIMEOUT);

        $result = app(OfflineFallback::class)->submit($this->invoice());

        $this->assertSame(1, DB::table('offline_queue')->count(), 'The invoice was not queued.');
        $this->assertNotEmpty($result);
    }

    /**
     * A filing diverted to the offline queue has no job: its submission row
     * says so, and why, rather than reading as a queued row whose job was
     * lost (the issue-and-file load run left three such rows).
     */
    public function test_offline_row_says_why_it_waits(): void
    {
        $invoice = $this->invoice();

        app(OfflineFallback::class)->submit($invoice, ['force_offline' => true, 'async' => true]);

        $submission = DB::table('invoice_submissions')->where('invoice_id', $invoice->id)->first();
        $this->assertSame('queued', $submission->state);
        $this->assertSame(ErrorCode::NET_HOST_UNREACHABLE->value, $submission->last_error_code);
        $this->assertNotEmpty($submission->last_error);
        $this->assertTrue(DB::table('submission_state_logs')->where(['submission_id' => $submission->id, 'trigger' => 'offline_fallback'])->exists());
    }

    /**
     * A queued document is signed and carries its QR, because the receipt is
     * printed now and submitted later.
     */
    public function test_queued_document_carries_its_qr(): void
    {
        $this->submissionFails(ErrorCode::ZATCA_SERVICE_UNAVAILABLE);

        $invoice = $this->invoice();
        app(OfflineFallback::class)->submit($invoice);

        $queued = DB::table('offline_queue')->first();

        $this->assertNotEmpty($queued->qr_code, 'The queued document has no QR code.');
        $this->assertNotEmpty($queued->signed_xml, 'The queued document is unsigned.');
        $this->assertNotEmpty($invoice->fresh()->qr_code, 'The invoice kept no QR code.');
    }

    /**
     * An invoice ZATCA actively rejected is not a connectivity problem.
     * Queueing it would retry a document that will be refused every time, and
     * hide a validation failure behind an outage.
     */
    public function test_rejection_is_not_queued(): void
    {
        $this->submissionFails(ErrorCode::ZATCA_INVALID_HASH);

        $this->expectException(FatooraException::class);

        try {
            app(OfflineFallback::class)->submit($this->invoice());
        } finally {
            $this->assertSame(0, DB::table('offline_queue')->count(), 'A rejected invoice was queued.');
        }
    }

    /**
     * When connectivity is already known to be down, nothing is attempted
     * against ZATCA at all.
     */
    public function test_known_outage_skips_the_attempt(): void
    {
        $tracker = \Mockery::mock(SubmissionTracker::class);
        // Queueing records a submission now, so the invoice is metered
        // and visible on the submissions screens. Allowed rather than
        // required here: two of the paths through this mock refuse to
        // queue at all. That the row is actually written is asserted in
        // InvoiceQuotaTest, against a real tracker.
        $tracker->shouldReceive('recordOffline');
        $tracker->shouldNotReceive('submit');
        $this->app->instance(SubmissionTracker::class, $tracker);

        $connectivity = \Mockery::mock(Connectivity::class);
        $connectivity->shouldReceive('shouldUseOfflineMode')->andReturn(true);
        $this->app->instance(Connectivity::class, $connectivity);

        app(OfflineFallback::class)->submit($this->invoice());

        $this->assertSame(1, DB::table('offline_queue')->count());
    }

    /**
     * Queueing an already-issued invoice leaves its hash alone.
     *
     * The guard here read "no signed_xml OR no hash OR no qr_code", and a
     * Phase 1 invoice issued without a certificate legitimately has
     * signed_xml null. So it fired on an invoice that was already issued,
     * already hashed and already recorded in hash_chain_history, and
     * overwrote invoices.hash with a freshly computed one - the signing
     * timestamp is part of the document, so the new hash necessarily differs.
     *
     * The next invoice derives its predecessor hash from invoices.hash, while
     * its chain entry records the old one, so the two stopped agreeing and
     * fatoora:verify-hash-chain reported a mismatch on a chain nobody had
     * touched. hash is in MUTABLE_AFTER_FINALIZED, so the immutability guard
     * allowed it.
     */
    public function test_an_issued_hash_is_not_rewritten(): void
    {
        $this->submissionFails(ErrorCode::NET_TIMEOUT);

        $invoice = $this->invoice();
        $hash = base64_encode(hash('sha256', 'the document the chain recorded', true));

        $invoice->update([
            'status' => 'issued',
            'icv' => 1,
            'hash' => $hash,
            'qr_code' => base64_encode('AQVTZWxsZXI='),
            'signed_xml' => null,
        ]);

        try {
            app(OfflineFallback::class)->submit($invoice->fresh(['lines']));
            $this->fail('An invoice issued without a signature was queued for filing.');
        } catch (FatooraException $e) {
            $this->assertSame(ErrorCode::VAL_INVOICE_ALREADY_ISSUED, $e->getErrorCode());
        }

        $this->assertSame(
            $hash,
            $invoice->fresh()->hash,
            'the hash hash_chain_history recorded was overwritten, so the next invoice derives a predecessor the chain never held'
        );

        $this->assertSame(0, DB::table('offline_queue')->count(), 'an unfilable document was queued');
    }

    /**
     * And a queue insert that fails leaves no hash behind either.
     *
     * The invoice update and the queue insert were two writes with no
     * transaction. Dying between them left an invoice carrying a hash and a
     * QR with no queue row - nothing would file it, and nothing would report
     * it overdue, because ReportOverdueSubmissions reads invoice_submissions
     * and this path never writes one. For a simplified invoice that is the
     * twenty-four hour reporting deadline passing in silence.
     */
    public function test_a_failed_queue_writes_no_hash(): void
    {
        $this->submissionFails(ErrorCode::NET_TIMEOUT);

        OfflineItem::creating(function (): void {
            throw new RuntimeException('the queue insert failed');
        });

        $invoice = $this->invoice();

        try {
            app(OfflineFallback::class)->submit($invoice);
        } catch (RuntimeException) {
            // The failure propagates; what matters is what it left behind.
        }

        $this->assertNull(
            $invoice->fresh()->hash,
            'the invoice was hashed for a queue row that was never written, so nothing will ever file it'
        );

        $this->assertSame(0, DB::table('offline_queue')->count());
    }

    private function submissionFails(ErrorCode $code): void
    {
        $connectivity = \Mockery::mock(Connectivity::class);
        $connectivity->shouldReceive('shouldUseOfflineMode')->andReturn(false);
        $this->app->instance(Connectivity::class, $connectivity);

        $tracker = \Mockery::mock(SubmissionTracker::class);
        // Queueing records a submission now, so the invoice is metered
        // and visible on the submissions screens. Allowed rather than
        // required here: two of the paths through this mock refuse to
        // queue at all. That the row is actually written is asserted in
        // InvoiceQuotaTest, against a real tracker.
        $tracker->shouldReceive('recordOffline');
        $tracker->shouldReceive('submit')->andThrow(new FatooraException('ZATCA unreachable', $code));
        $this->app->instance(SubmissionTracker::class, $tracker);
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->organization->id,
            'invoice_number' => 'POS-1',
            'type' => 'simplified',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }
}
