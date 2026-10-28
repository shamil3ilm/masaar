<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\DeadlineReport;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime;
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
 * A till that was offline sends its sales when it reconnects, and they are
 * issued then, as ZATCA's Detailed Guidelines for E-Invoicing require:
 *
 * - "Invoice Issue Date must be the date on which final Tax Invoice is
 *   generated" (p60), so IssueDate, IssueTime and the QR's timestamp are the
 *   moment the platform generates the invoice, not the sale's;
 * - a simplified invoice is reported within twenty-four hours of issuance
 *   (p12, p18, p32), so the reporting clock starts at issue;
 * - the issue date may not be in the future (p55, BR-KSA-04).
 *
 * The sale time is the supply: its date is the supply date (KSA-5, the
 * actual delivery date) and the daily close counts the sale on it. A late
 * sale is never refused; only one the platform's clock says has not happened
 * yet is.
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

    public function test_issue_time_is_generation_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17T10:00:00+03:00'));
        $sold = now()->subHours(13)->startOfSecond();

        $invoice = $this->issue($this->draft($sold));

        $this->assertTrue(now()->equalTo($invoice->issued_at));
        $this->assertTrue($sold->equalTo($invoice->sold_at));
        $this->assertSame('2026-10-17', $invoice->issue_date->toDateString(), 'the issue date is the sale\'s, not the day it was generated');
        $this->assertSame('2026-10-16', $invoice->supply_date->toDateString());
        $this->assertSame(['2026-10-17', '10:00:00', '2026-10-16'], [$this->xml($invoice, 'IssueDate'), $this->xml($invoice, 'IssueTime'), $this->xml($invoice, 'ActualDeliveryDate')]);
    }

    /** Days late is still issued, and dated today. */
    public function test_a_late_sale_is_issued(): void
    {
        $invoice = $this->issue($this->draft(now()->subDays(3)));

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame(FatooraTime::saudiNow()->toDateString(), $invoice->issue_date->toDateString());
    }

    public function test_a_future_sale_is_refused(): void
    {
        $draft = $this->draft(now()->addMinutes(5));

        $this->assertRefused($draft, 'ahead of the platform');
    }

    /**
     * The deadline runs from issuance. Sold twenty hours ago and issued just
     * now, its twenty-four hours have just begun.
     */
    public function test_the_deadline_counts_from_issuance(): void
    {
        $invoice = $this->issue($this->draft(now()->subHours(20)));

        InvoiceSubmission::acrossTenants()->create([
            'invoice_id' => $invoice->id,
            'org_id' => $this->acme->id,
            'state' => 'failed',
            'submission_type' => 'reporting',
        ]);

        $this->assertSame(0.0, round(app(DeadlineReport::class)->ageInHours(InvoiceSubmission::acrossTenants()->firstOrFail()), 1));
        $this->assertCount(0, app(DeadlineReport::class)->breakdown()['at_risk']);
    }

    /**
     * Through the API, the sale time names the supply date when none is
     * given; the invoice is dated today. issued_at, the name it was first
     * sent under, still works.
     */
    public function test_the_api_takes_a_sale_time(): void
    {
        $sold = Carbon::parse('2026-10-16T23:30:00+03:00');
        Carbon::setTestNow($sold->copy()->addHours(2));

        foreach (['sold_at', 'issued_at'] as $index => $field) {
            $response = $this->api([$field => $sold->toIso8601String(), 'invoice_number' => 'TILL-'.$index])->assertCreated();

            $this->assertSame('2026-10-17', substr((string) $response->json('data.invoice.issue_date'), 0, 10));
            $this->assertSame('2026-10-16', substr((string) $response->json('data.invoice.supply_date'), 0, 10));
            $this->assertTrue($sold->equalTo(Invoice::acrossTenants()->where('invoice_number', 'TILL-'.$index)->firstOrFail()->sold_at));
        }
    }

    public function test_the_api_wants_a_time_zone(): void
    {
        $this->api(['sold_at' => '2026-10-16 23:30:00'])->assertStatus(422);
        $this->api(['issued_at' => '2026-10-16 23:30:00'])->assertStatus(422);
    }

    private function assertRefused(Invoice $draft, string $why): void
    {
        try {
            $this->issue($draft);
            $this->fail('A sale in the future was issued.');
        } catch (FatooraException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }

        $stored = $draft->fresh();
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
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ...$fields,
        ]);
    }

    private function draft(Carbon $sold): Invoice
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => $sold->copy()->setTimezone('Asia/Riyadh')->toDateString(),
            'supply_date' => $sold->copy()->setTimezone('Asia/Riyadh')->toDateString(),
            'sold_at' => $sold,
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Widget',
            'description_ar' => 'أداة',
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

        return $invoice->fresh();
    }

    private function xml(Invoice $invoice, string $element): string
    {
        $xml = new DOMDocument;
        $xml->loadXML((string) $invoice->signed_xml);
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return (string) $xpath->evaluate('string(//cbc:'.$element.')');
    }
}
