<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\OfflineFallback;
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
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']
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

    /**
     * The allowance binds the submission itself, not only two routes.
     *
     * It was enforced by the license.quota middleware, which routes/api/partner.php
     * puts on /compliance/submit and /pipeline/submit. The tenant API, the
     * UAE FTA route and portal issuance all reached SubmissionTracker without
     * it, so a taxpayer past their tier kept filing from any of them. The
     * reservation is inside createSubmission's transaction now, which every
     * path goes through - including this one, which calls the service
     * directly and carries no middleware at all.
     */
    public function test_the_quota_binds_every_path(): void
    {
        app(SubmissionTracker::class)->submit($this->issued('INV-1'));
        app(SubmissionTracker::class)->submit($this->issued('INV-2'));

        $this->expectExceptionMessageMatches('/quota|limit/i');

        app(SubmissionTracker::class)->submit($this->issued('INV-3'));
    }

    /**
     * A submission that reached no verdict gives its slot back.
     *
     * The slot has to be taken before the authority is called, because a
     * limit enforced afterwards is not a limit. So when no answer came,
     * nothing was filed and the allowance should not have been spent.
     */
    public function test_a_failed_submission_returns_its_slot(): void
    {
        app(SubmissionTracker::class)->submit($this->issued('INV-1'));

        $this->assertSame(1, (int) LicenseUsage::where('license_id', $this->license->id)->sum('invoices_submitted'));

        app(UsageMeteringService::class)->recordSubmissionOutcome($this->organization->id, 'failed');

        $this->assertSame(
            0,
            (int) LicenseUsage::where('license_id', $this->license->id)->sum('invoices_submitted'),
            'a submission that never reached the authority kept the allowance it reserved'
        );
    }

    /**
     * An invoice queued during an outage is metered too.
     *
     * The allowance is reserved in SubmissionTracker::createSubmission, whose
     * own comment calls it "the one place every submission path passes". It
     * was not: OfflineFallback::queueForOffline writes the signed document
     * and the queue item and returns, without going through the tracker at
     * all. So an invoice filed while the authority was unreachable cost
     * nothing against the licence - and forcing offline mode is a switch on
     * the admin screen, which makes the whole allowance optional.
     *
     * It is also why a queued invoice appeared on no submissions screen: the
     * portal and the admin logs both read invoice_submissions, and this path
     * wrote no row there.
     */
    public function test_an_offline_invoice_is_metered(): void
    {
        $invoice = $this->issued('INV-OFF');

        app(OfflineFallback::class)->submit($invoice, ['force_offline' => true]);

        $this->assertSame(
            1,
            (int) LicenseUsage::where('license_id', $this->license->id)->sum('invoices_submitted'),
            'an invoice queued offline cost nothing against the licence'
        );

        $this->assertSame(
            1,
            InvoiceSubmission::withoutTenantScope(
                fn () => InvoiceSubmission::where('invoice_id', $invoice->id)->count()
            ),
            'a queued invoice is invisible on every submissions screen'
        );
    }

    /**
     * Falling back after a failed attempt charges one slot, not two.
     *
     * This is the ordinary way into the offline queue: the online attempt
     * throws a connectivity error, handleSubmissionError leaves a row in
     * 'failed' having already taken a slot, and OfflineFallback then queues
     * the invoice. A fresh row there would charge the taxpayer twice for one
     * invoice - and 'failed' is neither in-flight nor accepted, so
     * assertNotSubmitted would not have stopped it.
     */
    public function test_a_fallback_charges_one_slot(): void
    {
        $invoice = $this->issued('INV-FB');
        $tracker = app(SubmissionTracker::class);

        $first = $tracker->recordOffline($invoice);
        // As handleSubmissionError leaves it after a connectivity error.
        $first->update(['state' => 'failed']);

        $second = $tracker->recordOffline($invoice->fresh());

        $this->assertSame($first->id, $second->id, 'the fallback opened a second submission');

        $this->assertSame(
            1,
            (int) LicenseUsage::where('license_id', $this->license->id)->sum('invoices_submitted'),
            'one invoice cost two slots'
        );
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
            'name' => 'Console Only', 'country' => 'SA', 'vat_number' => '311111111111113',
            'street' => 'King Fahd Road', 'building_number' => '99',
            'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);

        $credentials = $this->selfSignedCredentials();
        app(CredentialStore::class)->put(
            $other->id, null, CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']
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

        // The line the stored totals describe: an invoice with none breaks
        // BR-16 and is refused before issue.
        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'tax_category' => 'S',
            'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice, $organization);

        return $invoice->fresh(['lines']);
    }
}
