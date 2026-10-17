<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The authority's answer reaches the invoice, by the paths that file.
 *
 * InvoiceVerdict is the one place an invoice is marked accepted or rejected
 * and the one place ZATCA's stamped copy is kept - cleared_xml, which for a
 * standard invoice is the legal document the buyer is owed. Two things call
 * it: Submitter::submit and the offline queue's completion.
 *
 * The routes that file do not go through Submitter::submit. The API, the
 * portal, the pipeline and the queued job all go through SubmissionTracker,
 * whose ledger records the answer on invoice_submissions and never on the
 * invoice. So an invoice cleared through any of them stayed "issued" for
 * ever, and the stamped document ZATCA returned was dropped on the floor -
 * held in a response object for the length of the request and then gone.
 *
 * The API route did reach the verdict until a14b842, which moved it onto the
 * tracker for its idempotency and its submission row. That gained those and
 * lost this, and nothing noticed: ClearedDocumentTest and AcceptanceRecordTest
 * both call Submitter::submit directly, so they prove a path the routes do not
 * take.
 *
 * These go through the routes' own entry points.
 */
class FilingVerdictTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const STAMPED = '<Invoice>STAMPED BY ZATCA</Invoice>';

    private Organization $acme;

    private string $token;

    private bool $unreachable = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

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

        app(CredentialStore::class)->put(
            $this->acme->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']
        );

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);

        Http::fake([
            '*/invoices/clearance/*' => Http::response([
                'clearanceStatus' => 'CLEARED',
                'validationResults' => ['status' => 'PASS'],
                'clearedInvoice' => base64_encode(self::STAMPED),
            ]),
            '*/invoices/reporting/*' => fn () => $this->unreachable
                ? throw new ConnectionException('cURL error 7: Failed to connect')
                : Http::response([
                    'reportingStatus' => 'REPORTED',
                    'validationResults' => ['status' => 'PASS'],
                ]),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    /**
     * A standard invoice cleared through the API keeps the stamped copy.
     */
    public function test_the_api_keeps_the_stamped_copy(): void
    {
        $invoice = $this->issued('ACME-1', 'standard');

        $this->withToken($this->token)
            ->postJson('/api/compliance/sa/submit/'.$invoice->id)
            ->assertOk();

        $fresh = $invoice->fresh();

        $this->assertSame(InvoiceStatus::Accepted, $fresh->status, 'a cleared invoice still reads issued');
        $this->assertSame(self::STAMPED, $fresh->cleared_xml, 'ZATCA\'s stamped invoice was discarded');
    }

    /**
     * A simplified invoice reported through the tracker reads accepted.
     */
    public function test_the_tracker_records_the_verdict(): void
    {
        $invoice = $this->issued('ACME-2', 'simplified');

        app(SubmissionTracker::class)->submit($invoice);

        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
    }

    /**
     * And so does the queued job, which is how every B2C sale is filed.
     */
    public function test_the_job_records_the_verdict(): void
    {
        $invoice = $this->issued('ACME-3', 'simplified');

        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id,
            'org_id' => $this->acme->id,
            'state' => 'queued',
            'submission_type' => 'reporting',
            'submission_mode' => 'async',
        ]));

        $this->app->call([new ProcessFatooraSubmission($submission), 'handle']);

        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
    }

    /**
     * An outage is not a verdict, and must not be written as one.
     *
     * Rejected is what the authority says about a document. Recording it for
     * a connection that never opened would tell the operator the invoice was
     * wrong, and leave it in a state the routes will not file again.
     */
    public function test_an_outage_leaves_it_issued(): void
    {
        $invoice = $this->issued('ACME-4', 'simplified');
        $this->unreachable = true;

        app(SubmissionTracker::class)->submit($invoice);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    private function issued(string $number, string $type): Invoice
    {
        $standard = $type === 'standard';

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => $number,
            'type' => $type,
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => $standard ? 'Beta Industries' : null,
            'buyer_vat_number' => $standard ? '399999999800003' : null,
            'buyer_address' => $standard ? [
                'street' => 'Olaya Street',
                'building_number' => '4321',
                'district' => 'Al Murooj',
                'city' => 'Riyadh',
                'postal_code' => '11564',
                'country_code' => 'SA',
            ] : null,
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Widget',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);

        return $invoice->fresh(['lines']);
    }
}
