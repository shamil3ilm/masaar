<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use DomainException;

/**
 * Renewing a production certificate from the portal - head office's, or
 * any till's - the three onboarding steps run again, one after another. A
 * till not yet connected is connected the same way, with its own OTP.
 *
 * A new OTP buys a compliance certificate on a new key, the six compliance
 * documents pass on it, and the production certificate it then gets becomes
 * the active version; the old one is superseded and still files what it
 * signed (CredentialStore). RenewalGuard is asked at the first step and the
 * last, as on the API.
 *
 * The OTP is spent at the first step. If the checks or the last step fail,
 * the old certificate keeps signing and the renewal is pending: finish()
 * runs the remaining steps without another OTP.
 */
class CertificateRenewal
{
    public function __construct(
        private readonly OrganizationOnboarding $onboarding,
        private readonly CredentialStore $credentials,
        private readonly UnitOnboarding $units,
    ) {}

    /**
     * Whether the organization holds a production certificate to renew.
     */
    public function renewable(Organization $organization): bool
    {
        return ! empty($this->credentials->get((string) $organization->id, null, CredentialStore::PCSID)[CredentialStore::PCSID]);
    }

    /**
     * A compliance certificate on a key other than the production
     * certificate's: the OTP was spent and the renewal not finished.
     */
    public function pending(Organization $organization): bool
    {
        $compliance = $this->credentials->get((string) $organization->id, null, CredentialStore::CCSID);
        $production = $this->credentials->get((string) $organization->id, null, CredentialStore::PCSID);

        return $this->renewable($organization)
            && ! empty($compliance['privateKey'])
            && ($compliance['privateKey'] ?? null) !== ($production['privateKey'] ?? null);
    }

    /**
     * Whether a till holds a production certificate to renew.
     */
    public function tillRenewable(EgsUnit $unit): bool
    {
        return ! empty($this->credentials->get((string) $unit->org_id, (string) $unit->id, CredentialStore::PCSID)[CredentialStore::PCSID]);
    }

    /**
     * A till's OTP was spent and its steps not finished: a compliance
     * certificate on a key its production certificate - if it has one -
     * was not issued on.
     */
    public function tillPending(EgsUnit $unit): bool
    {
        $compliance = $this->credentials->get((string) $unit->org_id, (string) $unit->id, CredentialStore::CCSID);
        $production = $this->credentials->get((string) $unit->org_id, (string) $unit->id, CredentialStore::PCSID);

        return ! empty($compliance['privateKey'])
            && ($compliance['privateKey'] ?? null) !== ($production['privateKey'] ?? null);
    }

    /**
     * A till's three steps on its own OTP: its first certificate, or its
     * next one. RenewalGuard is asked at the first step and the last.
     *
     * @return array{passed: bool, results: array<mixed>}
     *
     * @throws DomainException when the till cannot be onboarded, or RenewalGuard holds it
     * @throws CertificateException when ZATCA refuses
     */
    public function renewTill(Organization $organization, EgsUnit $unit, string $otp, ?string $overrideReason): array
    {
        return $this->units->connect($organization, $unit, $otp, $overrideReason);
    }

    /**
     * The checks and the production certificate, for a till whose OTP is
     * already spent.
     *
     * @return array{passed: bool, results: array<mixed>}
     *
     * @throws DomainException when nothing is waiting to be finished, or RenewalGuard holds it
     * @throws CertificateException when ZATCA refuses
     */
    public function finishTill(Organization $organization, EgsUnit $unit, ?string $overrideReason): array
    {
        if (! $this->tillPending($unit)) {
            throw new DomainException(__('No renewal is waiting to be finished.'));
        }

        return $this->units->finish($organization, $unit, $overrideReason);
    }

    /**
     * All three steps.
     *
     * @param  array{common_name: string, serial_number: string, organization_unit?: string|null, industry?: string|null}  $device
     * @return array{passed: bool, results: array<mixed>}
     *
     * @throws DomainException when there is nothing to renew, or RenewalGuard holds it
     * @throws CertificateException when ZATCA refuses
     */
    public function renew(Organization $organization, string $otp, array $device, ?string $overrideReason): array
    {
        if (! $this->renewable($organization)) {
            throw new DomainException(__('There is no production certificate to renew. Connect to ZATCA first.'));
        }

        $this->onboarding->requestCcsid($organization, $otp, $device, $overrideReason);

        return $this->finish($organization, $overrideReason);
    }

    /**
     * The compliance checks and the production certificate, for a renewal
     * whose OTP is already spent.
     *
     * @return array{passed: bool, results: array<mixed>}
     *
     * @throws DomainException when no renewal is pending, or RenewalGuard holds it
     * @throws CertificateException when ZATCA refuses
     */
    public function finish(Organization $organization, ?string $overrideReason): array
    {
        if (! $this->pending($organization)) {
            throw new DomainException(__('No renewal is waiting to be finished.'));
        }

        $checks = $this->onboarding->runComplianceCheck($organization);

        if ($checks['passed']) {
            $this->onboarding->requestPcsid($organization, $overrideReason);
        }

        return $checks;
    }
}
