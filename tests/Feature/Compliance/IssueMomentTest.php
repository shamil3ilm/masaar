<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Helpers\FatooraTime;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\DeadlineReport;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * An invoice's issue time is when it was issued, not when its draft began.
 *
 * KSA-25, the QR's timestamp and the 24-hour reporting clock all read
 * created_at, so a draft started on Monday and issued on Wednesday went out
 * saying Monday.
 */
class IssueMomentTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $credentials = $this->selfSignedCredentials();

        $this->organization = Organization::create([
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

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret'],
        );
    }

    public function test_the_document_carries_the_issue_time(): void
    {
        $this->travelTo(now()->setTime(9, 15, 0));
        $invoice = $this->draft();

        $this->travelTo(now()->addDays(2)->setTime(14, 40, 30));
        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization);

        $issued = $invoice->fresh();
        $this->assertNotNull($issued->issued_at);
        $this->assertSame(now()->toDateTimeString(), $issued->issued_at->toDateTimeString());
        $this->assertStringContainsString(
            '<cbc:IssueTime>'.FatooraTime::toSaudiTime(now())->format('H:i:s').'</cbc:IssueTime>',
            (string) $issued->signed_xml,
        );
    }

    /**
     * Thirty hours after the draft began but two after issue: not late.
     */
    public function test_the_clock_starts_at_issue(): void
    {
        $this->travelTo(now()->subHours(30));
        $invoice = $this->draft('simplified');

        $this->travelBack();
        $this->travelTo(now()->subHours(2));
        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization);
        $this->travelBack();

        $report = app(DeadlineReport::class)->breakdown();

        $this->assertSame(0, $report['unrecorded_total']);
        $this->assertNotContains((string) $invoice->id, $report['unrecorded']->pluck('invoice_id')->map(fn ($id) => (string) $id)->all());
    }

    private function draft(string $type = 'standard'): Invoice
    {
        $invoice = Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => 'ACME-'.uniqid(),
            'type' => $type,
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '300000000000003',
            'buyer_address' => ['street' => 'Tahlia', 'building_number' => '1111', 'district' => 'Rawdah', 'city' => 'Jeddah', 'postal_code' => '22222', 'country_code' => 'SA'],
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        return $invoice;
    }
}
