<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A late report is still a report.
 *
 * ZATCA gives a simplified invoice twenty-four hours to be reported, and
 * missing that is a penalty - not a release from reporting. Submitter refused
 * to send anything older, so an invoice that missed the window could never be
 * reported at all: the one outcome worse than being late.
 *
 * It also measured from issue_date, a calendar date read as midnight, so an
 * invoice issued at 23:00 arrived looking twenty-three hours old.
 *
 * Lateness is surfaced instead, by the overdue sweep and the deadline screen.
 */
class LateReportTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    public function test_a_late_invoice_is_still_reported(): void
    {
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        Http::fake(['*' => Http::response([
            'reportingStatus' => 'REPORTED',
            'validationResults' => ['status' => 'PASS'],
        ])]);

        $acme = Organization::create([
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

        $keys = $this->selfSignedCredentials();

        app(CredentialStore::class)->put($acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'],
            'pcsid' => $keys['certificate'],
            'secret' => 'device-secret',
        ]);

        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $acme->id,
            'invoice_number' => 'ACME-OLD',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->subDays(3)->toDateString(),
            'supply_date' => now()->subDays(3)->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Widget',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        app(Submitter::class)->submit($invoice->fresh(['lines']), $acme->fresh());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/invoices/reporting/'));
        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
    }
}
