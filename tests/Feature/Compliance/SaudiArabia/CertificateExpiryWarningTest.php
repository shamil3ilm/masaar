<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * "Certificate expiring soon" is said of a certificate that is, with the
 * days it has left, and once per certificate per threshold - not on every
 * invoice, and not of one valid for years (Carbon 3's diffIn* is signed).
 */
class CertificateExpiryWarningTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds', 'fatoora.retry_attempts' => 1]);
        Queue::fake();
        Http::fake(['*/invoices/reporting/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);
        $this->mock(Connectivity::class, fn ($mock) => $mock->shouldReceive('shouldUseOfflineMode')->andReturn(false));

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
    }

    public function test_a_distant_expiry_is_not_warned(): void
    {
        $this->certificate(days: 800);
        Log::spy();

        app(SubmissionTracker::class)->submit($this->issued('E-1'));

        Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($message) => $message === 'ZATCA certificate expiring soon'), \Mockery::any()]);
    }

    public function test_a_near_expiry_is_warned_once(): void
    {
        $this->certificate(days: 10);
        Log::spy();

        app(SubmissionTracker::class)->submit($this->issued('E-2'));
        app(SubmissionTracker::class)->submit($this->issued('E-3'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'ZATCA certificate expiring soon'
                && $context['days_remaining'] > 8 && $context['days_remaining'] <= 10)
            ->once();
    }

    private function certificate(int $days): void
    {
        $keys = $this->selfSignedCredentials(days: $days);
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);
    }

    private function issued(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id, 'invoice_number' => $number, 'type' => 'simplified', 'document_type' => 'invoice',
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));
        $invoice->lines()->create([
            'description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);
        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);

        return $invoice->fresh(['lines']);
    }
}
