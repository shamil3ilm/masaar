<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\KillSwitch;
use App\Domains\Compliance\Fatoora\Services\SubmissionTracker;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     */
    public function test_queued_job_respects_a_later_switch(): void
    {
        $submission = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $this->invoice($this->organization, 'INV-2')->id,
            'org_id' => $this->organization->id,
            'state' => 'queued',
            'submission_type' => 'clearance',
        ]));

        app(KillSwitch::class)->enable(KillSwitch::SWITCH_SUBMISSION, reason: 'incident');

        $this->assertBlockedByKillSwitch(
            fn () => $this->app->call([new ProcessFatooraSubmission($submission), 'handle'])
        );
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
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
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
