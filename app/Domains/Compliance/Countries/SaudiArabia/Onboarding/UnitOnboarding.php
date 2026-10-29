<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\CsrData;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\FilingResume;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\SellerParty;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateIdentity;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use DomainException;
use Illuminate\Support\Str;

/**
 * One till's own ZATCA onboarding: an OTP from the Fatoora portal for that
 * till, its compliance certificate, the six compliance documents, then its
 * production certificate.
 *
 * Each EGS unit is onboarded on its own, with its own OTP, and is known to
 * ZATCA by the serial its certificate request carries (E-Invoicing Detailed
 * Guidelines p10, 2.8 and 2.10: the CSID "uniquely identif[ies] their
 * unit"; p39: every unit used is registered). The serial is the till's
 * egs_serial, fixed when it was added, so a renewal keeps it.
 *
 * Head office's own unit is the organization's onboarding
 * (OrganizationOnboarding); every other till comes here, a branch's first
 * till included - the branch onboarding API does. What a step obtains is
 * stored under the till in the credential store, where signing and filing
 * read it. Onboarding a till that already holds a production certificate
 * is a renewal, held while documents it signed are unfiled (RenewalGuard).
 */
class UnitOnboarding
{
    public function __construct(
        private readonly CsidOnboarding $onboarding,
        private readonly CredentialStore $credentials,
        private readonly ComplianceSampleSet $samples,
        private readonly SampleStamp $stamp,
        private readonly OnboardingEvents $events,
        private readonly RenewalGuard $renewal,
        private readonly FilingResume $resume,
        private readonly OnboardingRecord $record,
    ) {}

    /**
     * Where the till is, step by step.
     *
     * @return array{has_ccsid: bool, has_pcsid: bool, steps: array<string, string>}
     */
    public function status(EgsUnit $unit): array
    {
        $ccsid = $this->stored($unit, CredentialStore::CCSID);
        $hasCcsid = $ccsid !== null;
        $hasPcsid = ! empty($this->stored($unit, CredentialStore::PCSID)[CredentialStore::PCSID]);

        return [
            'has_ccsid' => $hasCcsid,
            'has_pcsid' => $hasPcsid,
            'steps' => [
                'step_1' => $hasCcsid ? 'completed' : 'pending',
                // Completed only on a passing run (OnboardingRecord).
                'step_2' => match (true) {
                    $this->record->passed($ccsid) => 'completed',
                    $this->record->results($ccsid) !== null => 'failed',
                    // Onboarded before check runs were kept.
                    $hasPcsid || $unit->onboarding_status === Branch::STATUS_COMPLIANCE_PASSED => 'completed',
                    $hasCcsid => 'in_progress',
                    default => 'pending',
                },
                'step_3' => $hasPcsid ? 'completed' : 'pending',
            ],
        ];
    }

    /**
     * All three steps on one OTP, as the portal runs them: the compliance
     * certificate, the checks, and, when they pass, the production one.
     *
     * @return array{passed: bool, results: array<mixed>}
     *
     * @throws DomainException when the till cannot be onboarded, or renewal must wait
     * @throws CertificateException when ZATCA refuses
     */
    public function connect(Organization $organization, EgsUnit $unit, string $otp, ?string $renewalOverride = null): array
    {
        $this->requestCcsid($organization, $unit, $otp, $renewalOverride);

        return $this->finish($organization, $unit, $renewalOverride);
    }

    /**
     * The checks and the production certificate, once the OTP is spent.
     *
     * @return array{passed: bool, results: array<mixed>}
     *
     * @throws DomainException
     * @throws CertificateException
     */
    public function finish(Organization $organization, EgsUnit $unit, ?string $renewalOverride = null): array
    {
        $checks = $this->runComplianceCheck($organization, $unit);

        if ($checks['passed']) {
            $this->requestPcsid($organization, $unit, $renewalOverride);
        }

        return $checks;
    }

    /**
     * Step 1: the OTP for this till, for its compliance certificate. The OTP
     * is valid for an hour and is spent by this.
     *
     * @return array{ccsid: string, request_id: string}
     *
     * @throws DomainException when the organization's details are incomplete, the till retired, or renewal must wait
     * @throws CertificateException when ZATCA refuses
     */
    public function requestCcsid(Organization $organization, EgsUnit $unit, string $otp, ?string $renewalOverride = null): array
    {
        $this->assertOnboardable($organization, $unit);

        $this->renewal->assertMayRenew((string) $organization->id, (string) $unit->id, $renewalOverride);

        if (blank($unit->egs_serial)) {
            $unit->forceFill(['egs_serial' => Tills::serialFor((string) $unit->id)])->save();
        }

        try {
            $result = $this->onboarding->requestComplianceCsid($this->csr($organization, $unit), $otp, FatooraEnvironment::of($organization));
        } catch (CertificateException $e) {
            $this->record->step((string) $organization->id, (string) $unit->id, 'ccsid_refused', ['message' => $e->getMessage()]);

            throw $e;
        }

        $this->credentials->put((string) $organization->id, (string) $unit->id, CredentialStore::CCSID, $result);
        $this->record->step((string) $organization->id, (string) $unit->id, 'ccsid', [
            'environment' => $result['environment'] ?? FatooraEnvironment::of($organization),
            'serial_number' => $unit->egs_serial,
            'request_id' => $result['requestId'],
        ]);
        $this->mark($unit, Branch::STATUS_CSR_GENERATED);
        $this->events->announce((string) $organization->id, OnboardingEvents::CCSID_OBTAINED, $unit->branch_id, (string) $unit->id);

        return ['ccsid' => (string) $result['ccsid'], 'request_id' => (string) $result['requestId']];
    }

    /**
     * Step 2: the six documents ZATCA wants before a production certificate,
     * with the till's branch as the seller's address.
     *
     * @return array{passed: bool, results: array<mixed>}
     *
     * @throws DomainException when step 1 has not been done
     * @throws CertificateException when ZATCA refuses
     */
    public function runComplianceCheck(Organization $organization, EgsUnit $unit): array
    {
        $ccsid = $this->stored($unit, CredentialStore::CCSID)
            ?? throw new DomainException(__('No CCSID found. Complete step 1 first.'));

        // The seller as this till's documents will carry it (SellerParty):
        // the registered address, and its branch's own CR when it has one.
        $branch = SellerParty::branchOf($organization, (string) $unit->id);
        $documents = $this->samples->build(
            sellerName: $organization->documentName(),
            sellerVatNumber: $organization->vat_number ?? '',
            sellerCrNumber: SellerParty::crNumber($organization, $branch),
            sellerIdScheme: SellerParty::scheme($organization, $branch),
            sellerAddress: SellerParty::address($organization),
            numberPrefix: 'TEST-'.$unit->id,
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

        $this->record->checks((string) $organization->id, (string) $unit->id, $ccsid, $result);

        if ($result['passed']) {
            $this->mark($unit, Branch::STATUS_COMPLIANCE_PASSED);
            $this->events->announce((string) $organization->id, OnboardingEvents::COMPLIANCE_PASSED, $unit->branch_id, (string) $unit->id);
        }

        return ['passed' => (bool) $result['passed'], 'results' => $result['results']];
    }

    /**
     * Step 3: the till's production certificate, and the till ready.
     *
     * Already holding one on the compliance certificate's key means this
     * step ran and only its last write did not, and the OTP that bought it is
     * spent: the row is finished, nothing more is asked of ZATCA. One held on
     * another key is the certificate being renewed, and is kept as a
     * superseded version (CredentialStore).
     *
     * @throws DomainException when steps 1 and 2 have not been done, or renewal must wait
     * @throws CertificateException when ZATCA refuses
     */
    public function requestPcsid(Organization $organization, EgsUnit $unit, ?string $renewalOverride = null): void
    {
        $ccsid = $this->stored($unit, CredentialStore::CCSID)
            ?? throw new DomainException(__('No CCSID found. Complete steps 1-2 first.'));

        $existing = $this->stored($unit, CredentialStore::PCSID);

        if (empty($existing['pcsid']) || ($existing['privateKey'] ?? null) !== $ccsid['privateKey']) {
            // Asked again: documents may have been signed since step 1.
            $this->renewal->assertMayRenew((string) $organization->id, (string) $unit->id, $renewalOverride);
            $this->record->assertPassed((string) $organization->id, (string) $unit->id, $ccsid);

            try {
                $result = $this->onboarding->requestProductionCsid($ccsid['ccsid'], $ccsid['secret'], $ccsid['requestId'], CredentialStore::environmentOf($ccsid));
            } catch (CertificateException $e) {
                $this->record->step((string) $organization->id, (string) $unit->id, 'pcsid_refused', ['message' => $e->getMessage()]);

                throw $e;
            }

            $this->credentials->put((string) $organization->id, (string) $unit->id, CredentialStore::PCSID, array_merge($result, [
                'privateKey' => $ccsid['privateKey'],
                'environment' => CredentialStore::environmentOf($ccsid),
            ]));
            $existing = $this->stored($unit, CredentialStore::PCSID);
            $this->record->step((string) $organization->id, (string) $unit->id, 'pcsid', ['environment' => CredentialStore::environmentOf($ccsid)]);
        }

        $this->mark($unit, Branch::STATUS_ACTIVE, CertificateIdentity::of((string) $existing['pcsid'])->validTo);
        $this->events->announce((string) $organization->id, OnboardingEvents::PCSID_OBTAINED, $unit->branch_id, (string) $unit->id);

        // Credentials renewed: a device ZATCA had refused files again, and
        // what waited is queued for filing (FilingResume).
        $this->resume->resume((string) $organization->id, (string) $unit->id);
    }

    /**
     * The name the till's certificate request carries: EGS, its number, and
     * the organization's name.
     */
    public static function deviceName(Organization $organization, EgsUnit $unit): string
    {
        return 'EGS-T'.str_pad((string) $unit->till_number, 2, '0', STR_PAD_LEFT).'-'.Str::upper(Str::substr(Str::slug((string) $organization->name, ''), 0, 12));
    }

    /**
     * @throws DomainException
     */
    private function assertOnboardable(Organization $organization, EgsUnit $unit): void
    {
        if ($unit->isHeadOffice()) {
            throw new DomainException(__('Head office is onboarded as the organization, from the ZATCA page.'));
        }

        if (! $unit->issues()) {
            throw new DomainException(__('Till :till no longer issues invoices (:status). Issue from another till.', [
                'till' => $unit->label(),
                'status' => $unit->statusLabel(),
            ]));
        }

        if (! $organization->hasCompleteZatcaProfile()) {
            throw new DomainException(__('Organization profile incomplete. Ensure the VAT number, the commercial registration number and the national address are set.'));
        }
    }

    /**
     * A branch's first till asks as the branch always did: its device serial
     * as the common name, which its certificates were issued against. A till
     * added since names itself, and its serial number is its egs_serial.
     */
    private function csr(Organization $organization, EgsUnit $unit): CsrData
    {
        $branch = $unit->branch;

        if ($branch !== null && $branch->id === $unit->id) {
            return CsrData::forBranch($branch);
        }

        return new CsrData(
            organizationName: $organization->documentName(),
            organizationUnit: $branch !== null ? (string) $branch->name : 'Main Branch',
            commonName: self::deviceName($organization, $unit),
            vatNumber: (string) $organization->vat_number,
            serialNumber: (string) $unit->egs_serial,
            location: (string) ($branch->city ?? $organization->city ?? ''),
            industry: (string) ($branch->industry ?? 'General'),
        );
    }

    /**
     * Where the till's onboarding stands. A branch's first till is written
     * through its branch, whose onboarding columns are that till's
     * (Branch::booted), so the branch API reads the same.
     */
    private function mark(EgsUnit $unit, string $status, ?\DateTimeInterface $expires = null): void
    {
        $columns = ['onboarding_status' => $status];

        if ($status === Branch::STATUS_ACTIVE) {
            $columns['cert_expires_at'] = $expires;
        }

        if ($unit->branch !== null && $unit->branch->id === $unit->id) {
            $unit->branch->update($status === Branch::STATUS_ACTIVE ? [...$columns, 'onboarded_at' => now()] : $columns);
            $unit->refresh();

            return;
        }

        $unit->forceFill($columns)->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stored(EgsUnit $unit, string $type): ?array
    {
        return $this->credentials->get((string) $unit->org_id, (string) $unit->id, $type);
    }
}
