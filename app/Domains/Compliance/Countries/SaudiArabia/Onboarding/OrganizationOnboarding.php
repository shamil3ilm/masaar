<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\CsrData;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\FilingResume;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\SellerIdentification;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateIdentity;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Registrar;
use App\Domains\Organization\Services\Tills;
use App\Support\Brand;
use DomainException;
use Illuminate\Support\Str;

/**
 * An organization's own ZATCA onboarding: OTP to compliance certificate, the
 * six compliance documents, then the production certificate.
 *
 * The API controller held these steps, so the portal could not offer them
 * without a second copy. Both now call here. Each step stores what it
 * obtained in CredentialStore, where filing reads it.
 */
class OrganizationOnboarding
{
    public function __construct(
        private readonly CsidOnboarding $onboarding,
        private readonly CredentialStore $credentials,
        private readonly Registrar $registrar,
        private readonly ComplianceSampleSet $samples,
        private readonly SampleStamp $stamp,
        private readonly OnboardingEvents $events,
        private readonly RenewalGuard $renewal,
        private readonly FilingResume $resume,
        private readonly Tills $tills,
    ) {}

    /**
     * Where the organization is, step by step.
     *
     * @return array<string, mixed>
     */
    public function status(Organization $organization): array
    {
        $hasCcsid = $this->stored($organization, CredentialStore::CCSID) !== null;
        $hasPcsid = $this->stored($organization, CredentialStore::PCSID) !== null;

        return [
            // Which of ZATCA's environments this organization onboards into
            // and files with (FatooraEnvironment), so the portal can say so.
            'environment' => FatooraEnvironment::of($organization),
            'profile_complete' => $organization->hasCompleteZatcaProfile(),
            'vat_number_valid' => $organization->isValidVatNumber(),
            'has_ccsid' => $hasCcsid,
            'has_pcsid' => $hasPcsid,
            // Onboarded in this environment: a production certificate from
            // another one is not held here (CredentialStore).
            'is_onboarded' => ($organization->compliance_profile['zatca_onboarded'] ?? false) && $hasPcsid,
            'steps' => [
                'step_1' => $hasCcsid ? 'completed' : 'pending',
                'step_2' => $hasCcsid && ! $hasPcsid ? 'in_progress' : ($hasPcsid ? 'completed' : 'pending'),
                'step_3' => $hasPcsid ? 'completed' : 'pending',
            ],
        ];
    }

    /**
     * Step 1: exchange the OTP from the Fatoora portal for a compliance
     * certificate. The OTP is valid for an hour and is spent by this.
     *
     * @param  array{common_name: string, serial_number: string, organization_unit?: string|null, location?: string|null, industry?: string|null}  $device
     * @return array{ccsid: string, request_id: string}
     *
     * Onboarding an organization that holds a production certificate is a
     * renewal, refused while documents it signed are unfiled (RenewalGuard)
     * unless $renewalOverride gives a reason - before the OTP is spent.
     *
     * @throws DomainException when the organization's details are incomplete, or renewal must wait
     * @throws CertificateException when ZATCA refuses
     */
    public function requestCcsid(Organization $organization, string $otp, array $device, ?string $renewalOverride = null): array
    {
        if (! $organization->hasCompleteZatcaProfile()) {
            throw new DomainException(__('Organization profile incomplete. Ensure the VAT number, the commercial registration number and the national address are set.'));
        }

        $this->renewal->assertMayRenew((string) $organization->id, null, $renewalOverride);

        $result = $this->onboarding->requestComplianceCsid(new CsrData(
            organizationName: $organization->name,
            organizationUnit: $device['organization_unit'] ?? 'Main Branch',
            commonName: $device['common_name'],
            vatNumber: $organization->vat_number,
            serialNumber: $device['serial_number'],
            location: $device['location'] ?? $organization->city ?? '',
            industry: $device['industry'] ?? 'General',
        ), $otp, FatooraEnvironment::of($organization));

        $this->credentials->put((string) $organization->id, null, CredentialStore::CCSID, $result);

        // Head office's unit is known to ZATCA by the serial this request carried.
        $this->tills->headOffice((string) $organization->id)->forceFill([
            'egs_serial' => $device['serial_number'],
            'onboarding_status' => Branch::STATUS_CSR_GENERATED,
        ])->save();
        $this->events->announce((string) $organization->id, OnboardingEvents::CCSID_OBTAINED);

        return ['ccsid' => (string) $result['ccsid'], 'request_id' => (string) $result['requestId']];
    }

    /**
     * Step 2: the six documents ZATCA wants before a production certificate.
     *
     * @return array{passed: bool, results: array<mixed>}
     *
     * @throws DomainException when step 1 has not been done
     * @throws CertificateException when ZATCA refuses
     */
    public function runComplianceCheck(Organization $organization): array
    {
        $ccsid = $this->stored($organization, CredentialStore::CCSID)
            ?? throw new DomainException(__('No CCSID found. Complete step 1 first.'));

        $documents = $this->samples->build(
            sellerName: $organization->name,
            sellerVatNumber: $organization->vat_number ?? '',
            sellerCrNumber: $organization->cr_number,
            sellerIdScheme: SellerIdentification::scheme($organization),
            sellerAddress: $organization->getAddressData(),
            numberPrefix: 'TEST-'.time(),
            // Signed with the device's compliance CSID and QR-stamped, as
            // ZATCA judges them (BR-KSA-27 to 30, BR-KSA-60): SampleStamp.
            finalize: $this->stamp->with((string) $ccsid['privateKey'], (string) $ccsid['ccsid'], CredentialStore::environmentOf($ccsid)),
        );

        $result = $this->onboarding->runComplianceChecks(
            $ccsid['ccsid'],
            $ccsid['secret'],
            array_map(fn (array $document): string => $document['xml'], $documents),
            CredentialStore::environmentOf($ccsid),
        );

        if ($result['passed']) {
            $this->events->announce((string) $organization->id, OnboardingEvents::COMPLIANCE_PASSED);
        }

        return ['passed' => (bool) $result['passed'], 'results' => $result['results']];
    }

    /**
     * Step 3: the production certificate, and the organization onboarded.
     *
     * Already holding one means this step ran and only its last write did
     * not: the credential is a file and the flag is a row, so they cannot be
     * one transaction, and the OTP that bought the certificate is spent.
     * Finishing the row is the recovery; asking ZATCA for a second
     * certificate is not.
     *
     * Unless the one held was issued on a different key. A production
     * certificate is issued against the key its compliance request carried,
     * so a held one on another key belongs to an earlier onboarding, and
     * onboarding again - a new OTP, a new compliance CSID - is a renewal. The
     * store keeps the old certificate as a superseded version.
     *
     * @throws DomainException when steps 1 and 2 have not been done
     * @throws CertificateException when ZATCA refuses
     */
    public function requestPcsid(Organization $organization, ?string $renewalOverride = null): void
    {
        $ccsid = $this->stored($organization, CredentialStore::CCSID)
            ?? throw new DomainException(__('No CCSID found. Complete steps 1-2 first.'));

        $existing = $this->stored($organization, CredentialStore::PCSID);

        if (empty($existing['pcsid']) || ($existing['privateKey'] ?? null) !== $ccsid['privateKey']) {
            // A renewal: asked again here, since documents may have been
            // signed with the current certificate since step 1.
            $this->renewal->assertMayRenew((string) $organization->id, null, $renewalOverride);

            $result = $this->onboarding->requestProductionCsid($ccsid['ccsid'], $ccsid['secret'], $ccsid['requestId'], CredentialStore::environmentOf($ccsid));

            $this->credentials->put((string) $organization->id, null, CredentialStore::PCSID, array_merge($result, [
                'privateKey' => $ccsid['privateKey'],
                'environment' => CredentialStore::environmentOf($ccsid),
            ]));
        }

        // Through the service that owns organization writes, which locks the
        // row and re-reads it: compliance_profile holds several unrelated
        // keys, and a merge built from a copy loaded earlier discards
        // anything written in between.
        $this->registrar->markOnboarded($organization);
        $this->tills->headOffice((string) $organization->id)->forceFill([
            'onboarding_status' => Branch::STATUS_ACTIVE,
            'cert_expires_at' => CertificateIdentity::of((string) $this->stored($organization, CredentialStore::PCSID)['pcsid'])->validTo,
        ])->save();
        $this->events->announce((string) $organization->id, OnboardingEvents::PCSID_OBTAINED);

        // Credentials renewed: a device ZATCA had refused files again, and
        // what waited is queued for filing (FilingResume).
        $this->resume->resume((string) $organization->id, (string) $organization->id);
    }

    /** The device name the portal suggests: EGS1- and the organization's name. */
    public function deviceName(Organization $organization): string
    {
        return 'EGS1-'.Str::upper(Str::substr(Str::slug((string) $organization->name, ''), 0, 12));
    }

    /**
     * The serial number ZATCA expects for a device: the solution, its
     * version and the device, 1-<solution>|2-<version>|3-<device id>. Head
     * office's unit keeps the one it was onboarded under, so a renewal names
     * the same unit; before it has one, its id is the device's.
     */
    public function deviceSerial(?Organization $organization = null): string
    {
        if ($organization === null) {
            return '1-'.Str::slug(Brand::csrSolution()).'|2-1.0|3-'.Str::uuid();
        }

        $unit = $this->tills->headOffice((string) $organization->id);

        return filled($unit->egs_serial) ? (string) $unit->egs_serial : Tills::serialFor((string) $unit->id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stored(Organization $organization, string $type): ?array
    {
        return $this->credentials->get((string) $organization->id, null, $type);
    }
}
