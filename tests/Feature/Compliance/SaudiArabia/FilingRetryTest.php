<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FilingRetryException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseUsage;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A filing ZATCA did not answer is tried again, unless trying again could
 * file it twice.
 *
 * The job recorded a retryable failure - a 503, a timeout, a refused
 * connection - and returned normally, so the queue's retries never ran and
 * nothing else picked the submission up: a two-minute outage left invoices
 * unfiled until the overdue sweep, eighteen hours later.
 *
 * A report is safe to send again. A clearance is sent again only when it
 * certainly never reached the authority; one that may have - a read timeout,
 * a 5xx after the request went out - is held for reconciliation, the same
 * rule RecoverSubmissions follows.
 */
class FilingRetryTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    /** What the faked authority does next: ok, 503, 504, connect, timeout. */
    private string $answer = 'ok';

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
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $credentials = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']);

        $authority = function (string $status) {
            return fn () => match ($this->answer) {
                '503' => Http::response(['message' => 'Service Unavailable'], 503),
                '504' => Http::response(['message' => 'Gateway Timeout'], 504),
                'connect' => throw new ConnectionException('cURL error 7: Failed to connect to gw-fatoora.zatca.gov.sa port 443'),
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'),
                default => Http::response([$status => $status === 'clearanceStatus' ? 'CLEARED' : 'REPORTED', 'validationResults' => ['status' => 'PASS']]),
            };
        };

        Http::fake([
            '*/invoices/clearance/*' => $authority('clearanceStatus'),
            '*/invoices/reporting/*' => $authority('reportingStatus'),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_an_outage_is_retried(): void
    {
        $submission = $this->queued('simplified');
        $this->answer = '503';

        $this->assertRetried($submission);
        $this->assertSame('failed', $submission->fresh()->state);
    }

    public function test_a_refused_connection_is_retried(): void
    {
        $submission = $this->queued('standard');
        $this->answer = 'connect';

        $this->assertRetried($submission);
    }

    /**
     * A clearance that may have arrived is not sent again - ZATCA could be
     * holding it already - and the operator is told to reconcile.
     */
    public function test_an_ambiguous_clearance_is_held(): void
    {
        foreach (['504', 'timeout'] as $answer) {
            Log::spy();
            $submission = $this->queued('standard');
            $this->answer = $answer;

            $this->process($submission);

            Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'reconcil'));
        }
    }

    /**
     * Released and reserved again with each attempt, so an invoice that failed
     * and then got through costs one slot - not none, and not minus one.
     */
    public function test_a_retried_invoice_costs_one_slot(): void
    {
        License::createWithCredentials([
            'org_id' => $this->acme->id, 'organization_name' => 'Acme', 'contact_email' => 'ops@acme.test',
            'tier' => 'professional', 'status' => 'active', 'expires_at' => now()->addYear(),
        ]);

        $invoice = $this->issued('simplified');
        app(SubmissionTracker::class)->submit($invoice, null, async: true);
        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::where('invoice_id', $invoice->id)->firstOrFail());

        $this->answer = '503';
        $this->assertRetried($submission);
        $this->answer = 'ok';
        $this->process($submission->fresh());

        $this->assertSame('reported', $submission->fresh()->state);
        $this->assertSame(1, (int) LicenseUsage::query()->sum('invoices_submitted'));
    }

    /**
     * The last-resort handler does not overwrite an answer that arrived.
     */
    public function test_giving_up_keeps_a_recorded_answer(): void
    {
        $submission = $this->queued('simplified');
        $this->process($submission);
        $this->assertSame('reported', $submission->fresh()->state);

        (new ProcessFatooraSubmission($submission))->failed(new RuntimeException('worker timed out'));

        $this->assertSame('reported', $submission->fresh()->state);
    }

    private function assertRetried(InvoiceSubmission $submission): void
    {
        try {
            $this->process($submission);
        } catch (FilingRetryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('A retryable failure was recorded and dropped.');
    }

    private function process(InvoiceSubmission $submission): void
    {
        $this->app->call([new ProcessFatooraSubmission($submission), 'handle']);
    }

    private function queued(string $type): InvoiceSubmission
    {
        $invoice = $this->issued($type);

        return InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id,
            'org_id' => $this->acme->id,
            'state' => 'queued',
            'submission_type' => $type === 'standard' ? 'clearance' : 'reporting',
            'submission_mode' => 'async',
        ]));
    }

    private function issued(string $type): Invoice
    {
        $standard = $type === 'standard';

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => $type,
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => $standard ? 'Beta Industries' : null,
            'buyer_vat_number' => $standard ? '399999999800003' : null,
            'buyer_address' => $standard ? [
                'street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj',
                'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA',
            ] : null,
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
