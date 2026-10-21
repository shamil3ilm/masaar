<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineFallback;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\FilingPausedMail;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\SetupChecklist;
use App\Domains\Organization\Services\Tills;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * "401 Unauthorized - Check authentication certificate and secret and
 * resubmit" (E-Invoicing Detailed Guidelines, overview of responses): the
 * device stops filing, what it issues waits, its administrators are told
 * once, and renewing its certificate resumes it.
 */
class FilingPauseTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        Mail::fake();
        Queue::fake();

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);

        Http::fake(['*/invoices/*' => Http::response(['message' => 'Unauthorized'], 401)]);
    }

    public function test_refused_credentials_pause_the_device(): void
    {
        app(Submitter::class)->submit($this->issued('P-1'), $this->acme);
        $this->assertTrue($this->paused());

        try {
            app(Submitter::class)->submit($this->issued('P-2'), $this->acme);
            $this->fail('a paused device filed');
        } catch (FatooraException $e) {
            $this->assertSame(ErrorCode::ZATCA_UNAUTHORIZED, $e->getErrorCode());
        }

        Http::assertSentCount(1);
        Mail::assertSent(FilingPausedMail::class, 1);
        Mail::assertSent(FilingPausedMail::class, fn (FilingPausedMail $mail): bool => $mail->hasTo($this->admin->email));
    }

    public function test_a_paused_device_queues_new_invoices(): void
    {
        $this->pause();
        Http::fake(['*' => Http::response(['reportingStatus' => 'REPORTED'])]);
        $invoice = $this->issued('P-3');

        app(OfflineFallback::class)->submit($invoice, ['skip_connectivity_check' => true]);

        $this->assertTrue(app(OfflineQueue::class)->isQueued($invoice));
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/invoices/'));
    }

    public function test_the_offline_drain_skips_it(): void
    {
        $invoice = $this->issued('P-4');
        app(OfflineQueue::class)->queue($invoice, (string) $invoice->signed_xml, (string) $invoice->hash, (string) $invoice->qr_code);
        $this->pause();

        $this->artisan('fatoora:process-offline', ['--organization' => $this->acme->id, '--force' => true]);

        Http::assertNothingSent();
        $this->assertTrue(app(OfflineQueue::class)->isQueued($invoice));
    }

    public function test_renewal_resumes_and_queues_waiting(): void
    {
        $invoice = $this->issued('P-5');
        $this->pause();
        $renewed = $this->selfSignedCredentials(serial: 3);
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::CCSID, [
            'ccsid' => $renewed['certificate'], 'secret' => 's', 'requestId' => '9', 'privateKey' => $renewed['privateKey'],
            'compliance' => ['passed' => true],
        ]);
        Http::fake(['*/production/csids' => Http::response(['binarySecurityToken' => $renewed['certificate'], 'secret' => 'renewed', 'requestID' => 10])]);

        app(OrganizationOnboarding::class)->requestPcsid($this->acme, 'Credentials were refused; renewing them.');

        $this->assertFalse($this->paused());
        $this->assertTrue(app(OfflineQueue::class)->isQueued($invoice), 'what waited was not queued for filing');
    }

    public function test_portal_and_console_show_it(): void
    {
        $this->pause();

        $steps = collect(app(SetupChecklist::class)->steps($this->acme->fresh()));
        $this->assertTrue($steps->contains(fn (array $step): bool => str_starts_with($step['key'], 'zatca-paused-') && ! $step['done']));

        $this->asRequest(fn () => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.zatca.certificates')))
            ->assertOk()->assertSee('data-filing-paused', false);
    }

    private function pause(): void
    {
        app(Tills::class)->pauseFiling(app(Tills::class)->headOffice($this->acme->id), 'Unauthorized');
    }

    private function paused(): bool
    {
        return app(Tills::class)->headOffice($this->acme->id)->fresh()->filing_paused_at !== null;
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
