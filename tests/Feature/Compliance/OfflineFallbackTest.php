<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\Connectivity;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\OfflineFallback;
use App\Domains\Compliance\Fatoora\Services\SubmissionTracker;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
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
        $tracker->shouldNotReceive('submit');
        $this->app->instance(SubmissionTracker::class, $tracker);

        $connectivity = \Mockery::mock(Connectivity::class);
        $connectivity->shouldReceive('shouldUseOfflineMode')->andReturn(true);
        $this->app->instance(Connectivity::class, $connectivity);

        app(OfflineFallback::class)->submit($this->invoice());

        $this->assertSame(1, DB::table('offline_queue')->count());
    }

    private function submissionFails(ErrorCode $code): void
    {
        $connectivity = \Mockery::mock(Connectivity::class);
        $connectivity->shouldReceive('shouldUseOfflineMode')->andReturn(false);
        $this->app->instance(Connectivity::class, $connectivity);

        $tracker = \Mockery::mock(SubmissionTracker::class);
        $tracker->shouldReceive('submit')->andThrow(new FatooraException('ZATCA unreachable', $code));
        $this->app->instance(SubmissionTracker::class, $tracker);
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
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
        ]));

        return $invoice->fresh(['lines']);
    }
}
