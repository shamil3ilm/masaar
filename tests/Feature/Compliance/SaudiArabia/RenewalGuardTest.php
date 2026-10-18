<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * No renewal while what the current certificate signed is unfiled.
 *
 * A renewal revokes the existing certificate (Security Features
 * Implementation Standard v1.2, section 2.1.2, step 4), so a document it
 * stamped and that is still queued, retrying or overdue may then be refused.
 * The renewal waits; an emergency renews anyway with a reason, audited.
 */
class RenewalGuardTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        Http::fake([
            '*/compliance' => Http::response(['binarySecurityToken' => base64_encode('NEW-CCSID'), 'secret' => 's', 'requestID' => 1234567890]),
            '*' => Http::response(['ok' => true]),
        ]);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $this->store();
    }

    public function test_renewal_waits_for_unfiled_documents(): void
    {
        $this->issued('INV-QUEUED-1');

        try {
            $this->renew();
            $this->fail('a renewal went ahead with a document unfiled');
        } catch (DomainException $e) {
            $this->assertStringContainsString('INV-QUEUED-1', $e->getMessage());
        }

        // Refused before the OTP was spent.
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/compliance'));
    }

    public function test_override_with_reason_is_audited(): void
    {
        $this->issued('INV-QUEUED-2');

        $this->renew('The device key was exposed; renewing at once.');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/compliance'));
        $logged = DB::table('audit_logs')->where('action', 'zatca.renewal.override')->first();
        $this->assertNotNull($logged);
        $this->assertStringContainsString('INV-QUEUED-2', (string) $logged->metadata);
    }

    public function test_filed_documents_allow_renewal(): void
    {
        $invoice = $this->issued('INV-FILED-1');
        Invoice::withoutTenantScope(fn () => $invoice->update(['status' => InvoiceStatus::Accepted]));

        $this->renew();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/compliance'));
    }

    /**
     * A short reason is not a reason.
     */
    public function test_a_token_reason_does_not_override(): void
    {
        $this->issued('INV-QUEUED-3');

        $this->expectException(DomainException::class);
        $this->renew('urgent');
    }

    /**
     * The production step asks again: documents may have been signed since
     * the compliance certificate was requested.
     */
    public function test_production_step_asks_again(): void
    {
        $renewal = $this->selfSignedCredentials(serial: 9);
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::CCSID, [
            'ccsid' => base64_encode('NEW-CCSID'), 'secret' => 's', 'requestId' => '1', 'privateKey' => $renewal['privateKey'],
        ]);
        $this->issued('INV-QUEUED-4');

        $this->expectException(DomainException::class);
        app(OrganizationOnboarding::class)->requestPcsid($this->acme);
    }

    public function test_branch_reset_waits_then_overrides(): void
    {
        $branch = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id, 'name' => 'Jeddah', 'device_serial' => 'EGS-JED', 'industry' => 'Retail',
            'street' => 'Corniche Road', 'building_number' => '4321', 'district' => 'Al Hamra', 'city' => 'Jeddah', 'postal_code' => '23234',
            'onboarding_status' => Branch::STATUS_ACTIVE,
        ]));
        $this->store($branch->id);
        $this->issued('JED-QUEUED-1', $branch->id);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);

        $this->withToken($token)->postJson("/api/organizations/branches/{$branch->id}/onboarding/reset")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => __('One document signed with the current certificate has not been filed with ZATCA yet: :list. Renewing revokes that certificate, so file it first, or give a reason to renew anyway.', ['list' => 'JED-QUEUED-1'])]);

        $this->assertTrue(app(CredentialStore::class)->has($this->acme->id, $branch->id, CredentialStore::PCSID));

        $this->withToken($token)->postJson("/api/organizations/branches/{$branch->id}/onboarding/reset", [
            'renewal_override_reason' => 'Till stolen; the key must not stay in use.',
        ])->assertOk();
    }

    /**
     * The refusal reads in the reader's language.
     */
    public function test_the_refusal_reads_in_arabic(): void
    {
        $this->issued('INV-QUEUED-5');
        $this->issued('INV-QUEUED-6');
        $this->issued('INV-QUEUED-7');
        app()->setLocale('ar');

        try {
            $this->renew();
            $this->fail('the renewal went ahead');
        } catch (DomainException $e) {
            $this->assertStringContainsString('مستندات موقّعة بالشهادة الحالية', $e->getMessage());
            $this->assertStringContainsString('INV-QUEUED-7', $e->getMessage());
        }
    }

    private function renew(?string $reason = null): void
    {
        app(OrganizationOnboarding::class)->requestCcsid($this->acme, '123456', [
            'common_name' => 'EGS1', 'serial_number' => '1-Masaar|2-1.0|3-'.uniqid(),
        ], $reason);
    }

    private function store(?string $branchId = null): void
    {
        $keys = $this->selfSignedCredentials();

        app(CredentialStore::class)->put($this->acme->id, $branchId, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);
    }

    private function issued(string $number, ?string $branchId = null): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'branch_id' => $branchId,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);

        return $invoice->fresh();
    }
}
