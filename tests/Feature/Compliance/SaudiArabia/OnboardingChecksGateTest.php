<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\ComplianceFindings;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The production certificate only after all six compliance documents
 * passed (E-Invoicing Detailed Guidelines, onboarding: the compliance check
 * precedes the production CSID). The sandbox issued one anyway after six
 * refusals, and the portal said "Connected". Every step is audited, and a
 * refusal reads in plain language with ZATCA's own text behind it.
 */
class OnboardingChecksGateTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const SCHEMA_REFUSAL = "Schema validation failed; XML does not comply with UBL 2.1 standards in line with ZATCA specifications: cvc-complex-type.2.4.b: The content of element 'ext:ExtensionContent' is not complete.";

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);

        $compliance = $this->selfSignedCredentials(serial: 4);
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::CCSID, [
            'ccsid' => $compliance['certificate'], 'secret' => 's', 'requestId' => '42', 'privateKey' => $compliance['privateKey'],
        ]);
    }

    public function test_production_waits_for_passing_checks(): void
    {
        $this->checks(passing: false);
        $result = app(OrganizationOnboarding::class)->runComplianceCheck($this->acme);
        $this->assertFalse($result['passed']);

        try {
            app(OrganizationOnboarding::class)->requestPcsid($this->acme);
            $this->fail('a production certificate was asked for after refused checks');
        } catch (DomainException $e) {
            $this->assertStringContainsString('compliance', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/production/csids'));
        $status = app(OrganizationOnboarding::class)->status($this->acme->fresh());
        $this->assertNotSame('completed', $status['steps']['step_2']);
        $this->assertFalse($status['is_onboarded']);
    }

    public function test_passing_checks_open_production_step(): void
    {
        $this->checks(passing: true);
        app(OrganizationOnboarding::class)->runComplianceCheck($this->acme);

        app(OrganizationOnboarding::class)->requestPcsid($this->acme);

        $this->assertTrue(app(OrganizationOnboarding::class)->status($this->acme->fresh())['is_onboarded']);
        $this->assertSame(
            ['zatca.onboarding.compliance', 'zatca.onboarding.pcsid'],
            AuditLog::query()->where('org_id', $this->acme->id)->where('action', 'like', 'zatca.onboarding.%')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_refusals_read_in_plain_language(): void
    {
        $findings = ComplianceFindings::explain(['errors' => ['ZATCA returned status 400: '.self::SCHEMA_REFUSAL, 'BR-KSA-27: The document must contain a QR code (KSA-14)']]);

        $this->assertSame([
            __('The document is not in the structure ZATCA requires: part of it is missing or out of place.'),
            __('The invoice is missing its QR code.').' (BR-KSA-27)',
        ], array_column($findings, 'plain'));
        $this->assertStringContainsString('cvc-complex-type', $findings[0]['raw']);

        $this->checks(passing: false);
        $admin = User::factory()->create();
        $admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        app(OrganizationOnboarding::class)->runComplianceCheck($this->acme);

        $page = $this->asRequest(fn () => $this->actingAs($admin)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.zatca.show')))->assertOk();
        $page->assertSee(__('The document is not in the structure ZATCA requires: part of it is missing or out of place.'));
        $page->assertSee('data-zatca-raw', false);
    }

    private function checks(bool $passing): void
    {
        $renewed = $this->selfSignedCredentials(serial: 5);

        Http::fake([
            '*/compliance/invoices' => $passing
                ? Http::response(['validationResults' => ['status' => 'PASS'], 'reportingStatus' => 'REPORTED', 'clearanceStatus' => 'CLEARED'])
                : Http::response(['validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'XSD_ZATCA_INVALID', 'category' => 'XSD validation', 'message' => self::SCHEMA_REFUSAL]]]], 400),
            '*/production/csids' => Http::response(['binarySecurityToken' => $renewed['certificate'], 'secret' => 'p', 'requestID' => 43]),
        ]);
    }
}
