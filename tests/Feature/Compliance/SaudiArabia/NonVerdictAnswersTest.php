<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\DeviceCredentials;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\FatooraClient;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Only the authority judging the document rejects it.
 *
 * ZATCA's response table (E-Invoicing Detailed Guidelines, "Overview of
 * responses and required actions"): 400 is "Action failed (rejected) -
 * review detailed message, correct and resubmit"; 401 "check authentication
 * certificate and secret and resubmit"; 303 "clearance switched off - please
 * submit via reporting"; 413, 429, 500, 503 and 504 "invoice not received -
 * please resend". None but the 400 says anything about the document, and
 * each of them marked a valid, chained invoice Rejected.
 */
class NonVerdictAnswersTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        Queue::fake();

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);
    }

    /**
     * @return array<string, array{int, array<string, mixed>, ?ErrorCode, bool}>
     */
    public static function answers(): array
    {
        return [
            '401 credentials' => [401, [], ErrorCode::ZATCA_UNAUTHORIZED, false],
            '403 forbidden' => [403, [], ErrorCode::ZATCA_UNAUTHORIZED, false],
            '404 not found' => [404, [], ErrorCode::ZATCA_NOT_PROCESSED, false],
            '409 conflict' => [409, ['message' => 'conflict'], ErrorCode::ZATCA_NOT_PROCESSED, false],
            '413 too large' => [413, [], ErrorCode::ZATCA_NOT_PROCESSED, false],
            '400 without a verdict' => [400, ['message' => 'Bad Request'], ErrorCode::ZATCA_NOT_PROCESSED, false],
            '501 not implemented' => [501, [], ErrorCode::ZATCA_SERVICE_UNAVAILABLE, true],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('answers')]
    public function test_answer_is_not_a_verdict(int $status, array $body, ErrorCode $code, bool $retryable): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        $response = $this->client()->reportInvoice('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        $this->assertFalse($response->success);
        $this->assertFalse($response->isVerdict());
        $this->assertSame($code, $response->errorCode);
        $this->assertSame($retryable, $response->isRetryable());
        $this->assertNotSame([], $response->errorMessages, 'the reason was not kept');
    }

    public function test_a_validation_refusal_is_a_verdict(): void
    {
        Http::fake(['*' => Http::response([
            'validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-08', 'message' => 'seller id']]],
            'reportingStatus' => 'NOT_REPORTED',
        ], 400)]);

        $response = $this->client()->reportInvoice('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        $this->assertTrue($response->isVerdict());
        $this->assertNull($response->errorCode);
    }

    public function test_an_unexpected_exception_is_no_verdict(): void
    {
        Http::fake(fn () => throw new \RuntimeException('boom'));

        $response = $this->client()->reportInvoice('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        $this->assertFalse($response->isVerdict());
        $this->assertSame(ErrorCode::SYS_INTERNAL_ERROR, $response->errorCode);
    }

    /**
     * 303: clearance switched off, so the standard invoice is reported.
     */
    public function test_switched_off_clearance_is_reported(): void
    {
        Http::fake([
            '*/invoices/clearance/*' => Http::response('', 303),
            '*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']]),
        ]);

        $response = $this->client()->clearInvoice('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        $this->assertTrue($response->success);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/invoices/reporting/single')
            && ! $request->hasHeader('Clearance-Status'));
    }

    public function test_direct_submit_keeps_invoice_issued(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);
        $invoice = $this->issued('INV-401');

        app(Submitter::class)->submit($invoice, $this->acme);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    public function test_queued_submission_fails_without_rejecting(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);
        $invoice = $this->issued('INV-401-Q');
        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id, 'org_id' => $this->acme->id, 'state' => 'queued',
            'submission_type' => 'reporting', 'submission_mode' => 'async',
        ]));

        $this->app->call([new ProcessFatooraSubmission($submission), 'handle']);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $submission->refresh();
        $this->assertSame('failed', $submission->state);
        $this->assertNotEmpty($submission->zatca_errors, 'the reason was not recorded');
    }

    public function test_offline_item_stays_pending_on_401(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);
        $invoice = $this->issued('INV-401-O');
        app(OfflineQueue::class)->queue($invoice, (string) $invoice->signed_xml, (string) $invoice->hash, (string) $invoice->qr_code);

        $this->artisan('fatoora:process-offline', ['--organization' => $this->acme->id, '--force' => true]);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame(OfflineQueue::STATE_PENDING, \DB::table('offline_queue')->where('invoice_id', $invoice->id)->value('state'));
    }

    public function test_validation_refusal_still_rejects(): void
    {
        Http::fake(['*' => Http::response([
            'validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-08', 'message' => 'seller id']]],
            'reportingStatus' => 'NOT_REPORTED',
        ], 400)]);
        $invoice = $this->issued('INV-400');

        app(Submitter::class)->submit($invoice, $this->acme);

        $this->assertSame(InvoiceStatus::Rejected, $invoice->fresh()->status);
    }

    private function client(): FatooraClient
    {
        config(['fatoora.retry_attempts' => 1]);

        return app(FatooraClient::class);
    }

    private function issued(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);

        return $invoice->fresh(['lines']);
    }
}
