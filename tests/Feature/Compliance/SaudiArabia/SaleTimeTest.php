<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\DeadlineReport;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A till that was offline issues its sales at the time they were made.
 *
 * The moment of issue was always the server's, so a till catching up after
 * an outage issued yesterday's sales as today's: the wrong IssueTime and QR
 * timestamp, and a reporting clock that started a day late, so the deadline
 * screen said there was time left on an invoice already overdue. The till's
 * own sale time is taken now, within a bound: no further back than the
 * reporting deadline, and no further ahead than the clock tolerance.
 */
class SaleTimeTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Retail',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'],
            'pcsid' => $keys['certificate'],
            'secret' => 'device-secret',
        ]);
    }

    public function test_sale_time_is_the_issue_time(): void
    {
        $sold = now()->subHours(3)->startOfSecond();

        $invoice = $this->issue($this->draft($sold));

        $this->assertTrue($sold->equalTo($invoice->issued_at));
        $this->assertSame(
            $sold->copy()->setTimezone('Asia/Riyadh')->format('H:i:s'),
            $this->issueTime($invoice),
            'the document carries the server\'s time, not the sale\'s',
        );
    }

    public function test_an_old_sale_is_refused(): void
    {
        $draft = $this->draft(now()->subHours(25));

        $this->assertRefused($draft, 'at most 1440 minutes');
    }

    public function test_a_future_sale_is_refused(): void
    {
        $draft = $this->draft(now()->addMinutes(5));

        $this->assertRefused($draft, 'ahead of the platform');
    }

    public function test_the_bound_is_configurable(): void
    {
        config(['fatoora.sale_time.max_age_minutes' => 60]);

        $this->assertRefused($this->draft(now()->subHours(2)), 'at most 60 minutes');
    }

    /**
     * The deadline runs from the sale. Issued and submitted just now, it was
     * sold twenty hours ago, so it is at risk - and its age says twenty
     * hours, not none.
     */
    public function test_the_deadline_counts_from_sale(): void
    {
        $invoice = $this->issue($this->draft(now()->subHours(20)));

        InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id,
            'org_id' => $this->acme->id,
            'state' => 'failed',
            'submission_type' => 'reporting',
        ]));

        $atRisk = app(DeadlineReport::class)->breakdown()['at_risk'];

        $this->assertCount(1, $atRisk);
        $this->assertEqualsWithDelta(20.0, $atRisk->first()['age_hours'], 0.1);
    }

    /**
     * Sent through the API, a sale time names its own date when none is
     * given, and must agree with one that is.
     */
    public function test_the_api_takes_a_sale_time(): void
    {
        $sold = Carbon::parse('2026-10-16T23:30:00+03:00');
        Carbon::setTestNow($sold->copy()->addHours(2));

        $response = $this->api(['issued_at' => $sold->toIso8601String()])->assertCreated();

        $this->assertSame('2026-10-16', substr((string) $response->json('data.invoice.issue_date'), 0, 10));
        $this->assertTrue($sold->equalTo(Invoice::withoutTenantScope(fn () => Invoice::query()->firstOrFail())->issued_at));

        $this->api(['issued_at' => $sold->toIso8601String(), 'issue_date' => '2026-10-17'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.issue_date.0', __('The issue date must be the date of the sale time (issued_at), on the organization\'s clock.'));
    }

    public function test_the_api_wants_a_time_zone(): void
    {
        $this->api(['issued_at' => '2026-10-16 23:30:00'])->assertStatus(422);
    }

    private function assertRefused(Invoice $draft, string $why): void
    {
        try {
            $this->issue($draft);
            $this->fail('A sale time outside the bound was issued.');
        } catch (FatooraException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }

        $stored = Invoice::withoutTenantScope(fn () => $draft->fresh());
        $this->assertSame(InvoiceStatus::Draft, $stored->status);
        $this->assertNull($stored->icv, 'a refused sale took a counter');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function api(array $fields): TestResponse
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);

        return $this->withToken($token)->postJson('/api/invoices', [
            'type' => 'simplified',
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 100]],
            ...$fields,
        ]);
    }

    private function draft(Carbon $sold): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => $sold->copy()->setTimezone('Asia/Riyadh')->toDateString(),
            'issued_at' => $sold,
            'currency' => 'SAR',
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

        return $invoice;
    }

    private function issue(Invoice $invoice): Invoice
    {
        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme->fresh());

        return Invoice::withoutTenantScope(fn () => $invoice->fresh());
    }

    private function issueTime(Invoice $invoice): string
    {
        $xml = new DOMDocument;
        $xml->loadXML((string) $invoice->signed_xml);
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return (string) $xpath->evaluate('string(/*/cbc:IssueTime)');
    }
}
