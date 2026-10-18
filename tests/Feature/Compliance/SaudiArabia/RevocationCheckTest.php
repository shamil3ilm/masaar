<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationCheck;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\RevocationAuthority;
use Tests\TestCase;

/**
 * A certificate is checked against its CA's revocation list before it
 * stamps.
 *
 * "Before using the certificate for stamping, EGS/ZATCA e-invoicing platform
 * shall check certificate's validity against any of the above mentioned
 * sources", and "CRLs shall be valid for seven (7) days which would allow
 * EGSs to work fully offline for seven (7) days" (Security Features
 * Implementation Standard v1.2, section 2.2.1, requirement 14).
 *
 * The responder is faked three ways: serving a list that clears the
 * certificate, one that revokes it, and not answering at all.
 */
class RevocationCheckTest extends TestCase
{
    use RefreshDatabase;
    use RevocationAuthority;

    /** What the faked distribution point serves: a list, or 'down'. */
    private string $served = 'down';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds', 'security.revocation_hosts' => ['crl.authority.test']]);

        Http::fake([
            self::CRL_URL => function () {
                if ($this->served === 'down') {
                    throw new ConnectionException('cURL error 7: Failed to connect to crl.authority.test port 80');
                }

                return Http::response($this->served);
            },
            '*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']]),
        ]);
    }

    public function test_good_responder_clears_the_certificate(): void
    {
        $device = $this->issuedDevice(4097);
        $this->served = $this->revocationList([4096]);

        $status = app(RevocationCheck::class)->status($device['certificate']);

        $this->assertSame(RevocationCheck::GOOD, $status['state']);
        $this->assertSame(self::CRL_URL, $status['source']);
    }

    public function test_revoked_responder_refuses_the_stamp(): void
    {
        $device = $this->issuedDevice(4096);
        $this->served = $this->revocationList([4096]);

        $this->assertSame(RevocationCheck::REVOKED, app(RevocationCheck::class)->status($device['certificate'])['state']);
        $this->assertRefused($device['certificate'], ErrorCode::CERT_REVOKED);
    }

    /**
     * No list ever held, and none obtainable: nothing vouches for the
     * certificate, so it does not stamp.
     */
    public function test_unreachable_responder_without_list_refuses(): void
    {
        $device = $this->issuedDevice(4097);

        $this->assertSame(RevocationCheck::UNKNOWN, app(RevocationCheck::class)->status($device['certificate'])['state']);
        $this->assertRefused($device['certificate'], ErrorCode::CERT_REVOCATION_UNVERIFIED);
    }

    /**
     * A till that loses its connection keeps stamping on the list it holds,
     * for the seven days the standard allows.
     */
    public function test_held_list_covers_offline_days(): void
    {
        $device = $this->issuedDevice(4097);
        $this->served = $this->revocationList([], days: 30);
        app(RevocationCheck::class)->assertMayStamp($device['certificate']);

        $this->served = 'down';
        $this->travelTo(CarbonImmutable::now()->addDays(6)->addHours(23));

        app(RevocationCheck::class)->assertMayStamp($device['certificate']);
        $this->assertSame(RevocationCheck::GOOD, app(RevocationCheck::class)->status($device['certificate'])['state']);
    }

    /**
     * Seven days on, even a list that says it is current until later stops
     * counting.
     */
    public function test_held_list_lapses_after_seven_days(): void
    {
        $device = $this->issuedDevice(4097);
        $this->served = $this->revocationList([], days: 30);
        app(RevocationCheck::class)->assertMayStamp($device['certificate']);

        $this->served = 'down';
        $this->travelTo(CarbonImmutable::now()->addDays(7)->addMinute());

        $this->assertRefused($device['certificate'], ErrorCode::CERT_REVOCATION_UNVERIFIED);
    }

    /**
     * A list from anyone but the certificate's CA is not evidence.
     */
    public function test_list_signed_by_another_ca_ignored(): void
    {
        $device = $this->issuedDevice(4097);

        $impostor = new class
        {
            use RevocationAuthority;

            public function forged(): string
            {
                $chain = config('fatoora.certificate.chain_path');
                $list = $this->revocationList();
                config(['fatoora.certificate.chain_path' => $chain]);

                return $list;
            }
        };
        $this->served = $impostor->forged();

        $this->assertSame(RevocationCheck::UNKNOWN, app(RevocationCheck::class)->status($device['certificate'])['state']);
    }

    /**
     * A certificate that names no list, with none configured, has nothing to
     * be checked against. It stamps, and says so where it is reported.
     */
    public function test_no_list_named_stamps_unverified(): void
    {
        $device = $this->issuedDevice(4097, namesList: false);

        $this->assertSame(RevocationCheck::NO_SOURCE, app(RevocationCheck::class)->status($device['certificate'])['state']);
        app(RevocationCheck::class)->assertMayStamp($device['certificate']);
    }

    /**
     * The operator's list covers a certificate that names none.
     */
    public function test_configured_list_covers_unnamed_certificates(): void
    {
        config(['fatoora.certificate.crl_urls' => [self::CRL_URL]]);
        $device = $this->issuedDevice(4096, namesList: false);
        $this->served = $this->revocationList([4096]);

        $this->assertSame(RevocationCheck::REVOKED, app(RevocationCheck::class)->status($device['certificate'])['state']);
    }

    /**
     * Issuing asks before it signs: a revoked device's invoice is neither
     * numbered nor signed.
     */
    public function test_revoked_device_issues_nothing(): void
    {
        $organization = $this->onboarded($this->issuedDevice(4096));
        $this->served = $this->revocationList([4096]);
        $invoice = $this->draft($organization);

        try {
            app(Submitter::class)->generate($invoice, $organization);
            $this->fail('a revoked certificate signed an invoice');
        } catch (FatooraException $e) {
            $this->assertSame(ErrorCode::CERT_REVOKED, $e->getErrorCode());
        }

        $this->assertNull($invoice->fresh()->icv);
        $this->assertNull($invoice->fresh()->signed_xml);
    }

    public function test_cleared_device_issues_and_signs(): void
    {
        $organization = $this->onboarded($this->issuedDevice(4097));
        $this->served = $this->revocationList([4096]);
        $invoice = $this->draft($organization);

        app(Submitter::class)->generate($invoice, $organization);

        $this->assertNotNull($invoice->fresh()->signed_xml);
    }

    private function assertRefused(string $certificate, ErrorCode $code): void
    {
        try {
            app(RevocationCheck::class)->assertMayStamp($certificate);
            $this->fail('the certificate was allowed to stamp');
        } catch (FatooraException $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    /**
     * @param  array{privateKey: string, certificate: string}  $device
     */
    private function onboarded(array $device): Organization
    {
        $organization = Organization::create([
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

        app(CredentialStore::class)->put($organization->id, null, CredentialStore::PCSID, [
            'privateKey' => $device['privateKey'],
            'pcsid' => $device['certificate'],
            'secret' => 'device-secret',
        ]);

        return $organization;
    }

    private function draft(Organization $organization): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.uniqid(),
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

        return $invoice->fresh(['lines']);
    }
}
