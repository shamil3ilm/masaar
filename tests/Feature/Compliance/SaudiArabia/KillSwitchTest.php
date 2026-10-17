<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\FatooraClient;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Services\KillSwitch;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The emergency stop has to stop things.
 *
 * KillSwitch exists for an incident: a ZATCA outage at month-end close, a bad
 * go-live, a signing defect caught in production. It offers submission,
 * clearance, reporting and issuance switches, per tenant and globally, plus
 * emergencyStop().
 *
 * Nothing consulted any of it except the offline queue's batch loop. An
 * operator could throw the switch, watch replay stop, and reasonably conclude
 * submissions had halted — while every live submission carried on reaching the
 * authority. A control that reports success without acting is worse than no
 * control, because it is trusted during exactly the moment it matters.
 */
class KillSwitchTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    private Organization $other;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = $this->organization('Acme', '300000000000003');
        $this->other = $this->organization('Rival', '300000000000011');

        // ZATCA is never reached in these tests; if it is, that is the failure.
        $client = \Mockery::mock(FatooraClient::class);
        $client->shouldNotReceive('clearInvoice');
        $client->shouldNotReceive('reportInvoice');
        $this->app->instance(FatooraClient::class, $client);
    }

    /**
     * Asking whether a switch is on does not rewrite the registry.
     *
     * isEnabled read the whole registry and, on noticing a lapsed switch,
     * wrote the array back - so a switch an operator enabled between that
     * read and that write was erased by a request that was only asking
     * whether it could proceed. Submitter and SubmissionGuard call it on
     * every issuance and every submission, so the window was hit constantly.
     */
    /**
     * A switch set with no end date is still on hours later.
     *
     * The registry was written with a one-hour TTL, so the cache entry's
     * lifetime overrode every switch's own. An indefinite switch - the one
     * the console shows as "turned off by hand" - released an hour after the
     * last write, and nothing logged it: the auto-expiry path only ever saw
     * entries that were still in the cache.
     *
     * Worse in the other direction: max_duration_seconds is four hours, four
     * times that TTL, so even a deliberately time-boxed four-hour stop
     * released after one. The cap on how long a switch may be set for could
     * not be enforced in the direction that mattered.
     */
    public function test_an_indefinite_switch_persists(): void
    {
        app(KillSwitch::class)->enable(KillSwitch::SWITCH_SUBMISSION, reason: 'the authority is down');

        // Past the registry's old TTL, and past max_duration_seconds too.
        $this->travel(5)->hours();

        $this->assertTrue(
            app(KillSwitch::class)->isEnabled(KillSwitch::SWITCH_SUBMISSION),
            'a switch set with no end date released on its own'
        );
    }

    /**
     * And one that was given an end date still honours it.
     */
    public function test_a_time_boxed_switch_still_lapses(): void
    {
        app(KillSwitch::class)->enable(
            KillSwitch::SWITCH_SUBMISSION,
            reason: 'a short outage',
            durationSeconds: 600,
        );

        $this->travel(11)->minutes();

        $this->assertFalse(
            app(KillSwitch::class)->isEnabled(KillSwitch::SWITCH_SUBMISSION),
            'a switch outlived the duration it was given'
        );
    }

    public function test_a_read_does_not_write(): void
    {
        $switches = app(KillSwitch::class);

        // One switch that lapses and one that does not. The lapsed entry is
        // what used to trigger the housekeeping write.
        $switches->enable(KillSwitch::SWITCH_SUBMISSION, reason: 'outage', durationSeconds: 60);
        $switches->enable(KillSwitch::SWITCH_SIGNING, reason: 'maintenance');

        $this->travel(120)->seconds();

        // 'zatca:kill_switches' is KillSwitch::CACHE_KEY, which is private.
        $before = Cache::get('zatca:kill_switches');

        $switches->isEnabled(KillSwitch::SWITCH_SUBMISSION);
        $switches->isEnabled(KillSwitch::SWITCH_SIGNING);

        $this->assertEquals($before, Cache::get('zatca:kill_switches'), 'a read rewrote the registry');
        $this->assertTrue($switches->isEnabled(KillSwitch::SWITCH_SIGNING), 'the switch that had not lapsed is gone');
        $this->assertFalse($switches->isEnabled(KillSwitch::SWITCH_SUBMISSION), 'a lapsed switch still reads as on');
    }

    public function test_submission_switch_stops_submission(): void
    {
        app(KillSwitch::class)->enable(KillSwitch::SWITCH_SUBMISSION, reason: 'incident');

        $this->assertBlockedByKillSwitch(fn () => app(SubmissionTracker::class)
            ->submit($this->invoice($this->organization, 'INV-1')));
    }

    /**
     * B2B goes for clearance, so the clearance switch has to stop it even when
     * submission generally is permitted.
     */
    public function test_clearance_switch_stops_b2b(): void
    {
        app(KillSwitch::class)->enable(KillSwitch::SWITCH_CLEARANCE, reason: 'incident');

        $this->assertBlockedByKillSwitch(fn () => app(SubmissionTracker::class)
            ->submit($this->invoice($this->organization, 'INV-1')));
    }

    /**
     * Issuance is the irreversible half: the document is signed and the invoice
     * marked Issued. A signing defect caught in production is the case this
     * switch is for, and stopping submission alone would not stop it.
     */
    public function test_issuance_switch_stops_signing(): void
    {
        app(KillSwitch::class)->enable(KillSwitch::SWITCH_ISSUANCE, reason: 'signing defect');

        $invoice = $this->invoice($this->organization, 'INV-3');

        $this->assertBlockedByKillSwitch(fn () => app(Submitter::class)
            ->generate($invoice, $this->organization));

        $this->assertSame('draft', $invoice->fresh()->status->value, 'The invoice was issued anyway.');
    }

    /**
     * Containing one taxpayer's blast radius is the reason the switch is
     * scoped, so a switch thrown for one must not stop everyone else.
     */
    public function test_tenant_switch_leaves_others_running(): void
    {
        app(KillSwitch::class)->enable(
            KillSwitch::SWITCH_SUBMISSION,
            scope: KillSwitch::SCOPE_TENANT,
            tenantId: $this->organization->id,
            reason: 'one tenant only',
        );

        $blocked = app(KillSwitch::class)->isSubmissionBlocked($this->organization->id);
        $running = app(KillSwitch::class)->isSubmissionBlocked($this->other->id);

        $this->assertTrue($blocked, 'The named tenant was not blocked.');
        $this->assertFalse($running, 'An unrelated tenant was blocked too.');
    }

    /**
     * A job queued before the incident must not submit during it. The switch is
     * thrown in the gap between queueing and running, which is the whole reason
     * the job checks again rather than trusting the check made at queue time.
     *
     * And it must not fail either. The job threw, the queue retried at ten and
     * sixty seconds, and after three tries - about seventy seconds - marked
     * the submission permanently failed and told the tenant so. A stop of a
     * few minutes failed every queued invoice for good, and releasing the
     * switch resent none of them. The submission stays queued now, and
     * fatoora:recover-submissions sends it once the switch is released.
     */
    public function test_queued_job_respects_a_later_switch(): void
    {
        Http::fake();

        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $this->invoice($this->organization, 'INV-2')->id,
            'org_id' => $this->organization->id,
            'state' => 'queued',
            'submission_type' => 'clearance',
        ]));

        app(KillSwitch::class)->enable(KillSwitch::SWITCH_SUBMISSION, reason: 'incident');

        $this->app->call([new ProcessFatooraSubmission($submission), 'handle']);

        Http::assertNothingSent();
        $this->assertSame('queued', $submission->fresh()->state, 'a stop is not a failure');
    }

    /**
     * Asserting the specific refusal, not merely that something was refused.
     *
     * expectException(FatooraException::class) alone passed with the wiring
     * removed: an invoice for an organization with no onboarding is refused
     * anyway, by a different check, for a different reason. The error code is
     * what distinguishes "the switch stopped this" from "it was never going to
     * work regardless".
     */
    private function assertBlockedByKillSwitch(callable $operation): void
    {
        try {
            $operation();
        } catch (FatooraException $e) {
            $this->assertSame(
                ErrorCode::SYS_MAINTENANCE_MODE,
                $e->getErrorCode(),
                'Refused, but not by the kill switch: '.$e->getMessage()
            );

            return;
        }

        $this->fail('The kill switch did not stop the operation.');
    }

    /**
     * Releasing the switch releases it, or the stop becomes an outage of its
     * own.
     */
    public function test_disabling_restores_submission(): void
    {
        $switch = app(KillSwitch::class);

        $switch->enable(KillSwitch::SWITCH_SUBMISSION, reason: 'incident');
        $this->assertTrue($switch->isSubmissionBlocked($this->organization->id));

        $switch->disable(KillSwitch::SWITCH_SUBMISSION);
        $this->assertFalse($switch->isSubmissionBlocked($this->organization->id));
    }

    private function organization(string $name, string $vat): Organization
    {
        $organization = Organization::create([
            'name' => $name,
            'country' => 'SA',
            'vat_number' => $vat,
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $credentials = $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']
        );

        return $organization;
    }

    private function invoice(Organization $organization, string $number): Invoice
    {
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

        return $invoice->fresh(['lines']);
    }
}
