<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\SubmissionTracker;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseUsage;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The monthly invoice quota, which never counted anything.
 *
 * checkInvoiceQuota() sums license_usage.invoices_submitted for the month and
 * refuses once the tier's invoices_per_month is reached. The only writer of
 * that column is recordInvoiceSubmission(), and nothing called it. So the sum
 * was always zero: a starter licence limited to 100 invoices a month could
 * submit without end, and the usage endpoint reported a month of activity as
 * nothing at all.
 *
 * Metering is recorded where the outcome is known rather than when the request
 * arrives, so the synchronous and queued paths record the same thing.
 */
class InvoiceQuotaTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    private License $license;

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

        $this->license = License::createWithCredentials([
            'org_id' => $this->organization->id,
            'organization_name' => 'Acme Trading',
            'contact_email' => 'erp@acme.test',
            'tier' => 'starter',
            'invoices_per_month' => 2,
        ])['license'];

        $client = \Mockery::mock(FatooraClient::class);
        foreach (['clearInvoice', 'reportInvoice'] as $method) {
            $client->shouldReceive($method)->andReturn(new FatooraResponse(
                success: true,
                clearanceStatus: 'CLEARED',
                reportingStatus: null,
                validationStatus: 'PASS',
                clearedInvoice: null,
                validationResults: [],
                warningMessages: [],
                errorMessages: [],
                rawResponse: null,
            ));
        }
        $this->app->instance(FatooraClient::class, $client);
    }

    public function test_a_submission_is_metered(): void
    {
        app(SubmissionTracker::class)->submit($this->issued('INV-1'));

        $usage = LicenseUsage::where('license_id', $this->license->id)->first();

        $this->assertNotNull($usage, 'The submission was not metered.');
        $this->assertSame(1, (int) $usage->invoices_submitted);
        $this->assertSame(1, (int) $usage->invoices_cleared);
    }

    /**
     * The quota is the point of the counter. Reaching it has to refuse the next
     * submission rather than record it and continue.
     */
    public function test_quota_refuses_past_the_limit(): void
    {
        app(SubmissionTracker::class)->submit($this->issued('INV-1'));
        app(SubmissionTracker::class)->submit($this->issued('INV-2'));

        $metering = app(UsageMeteringService::class);

        $this->expectExceptionMessageMatches('/quota|limit/i');

        $metering->checkInvoiceQuota($this->license->fresh());
    }

    /**
     * Below the limit the quota admits, or the enforcement would be a different
     * kind of broken.
     */
    public function test_quota_admits_below_the_limit(): void
    {
        app(SubmissionTracker::class)->submit($this->issued('INV-1'));

        app(UsageMeteringService::class)->checkInvoiceQuota($this->license->fresh());

        $this->assertSame(1, (int) LicenseUsage::where('license_id', $this->license->id)->sum('invoices_submitted'));
    }

    /**
     * An organization with no licence submits through the staff console, where
     * the credential is a person rather than an integrator. Nothing to meter is
     * not the same as failing to meter.
     */
    public function test_unlicensed_org_still_submits(): void
    {
        // A licence cannot be deleted — the model refuses it to preserve the
        // audit trail — so this is an organization that never had one.
        $other = Organization::create([
            'name' => 'Console Only', 'country' => 'SA', 'vat_number' => '300000000000011',
            'street' => 'King Fahd Road', 'building_number' => '99',
            'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);

        $credentials = $this->selfSignedCredentials();
        app(CredentialStore::class)->put(
            $other->id, null, CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
        );

        app(SubmissionTracker::class)->submit($this->issued('INV-9', $other));

        $this->assertSame(0, LicenseUsage::count(), 'An organization with no licence was metered anyway.');
    }

    private function issued(string $number, ?Organization $organization = null): Invoice
    {
        $organization ??= $this->organization;

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '399999999900003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        app(Submitter::class)->generate($invoice, $organization);

        return $invoice->fresh(['lines']);
    }
}
