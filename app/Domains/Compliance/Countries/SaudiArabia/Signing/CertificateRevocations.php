<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\CertificateRevokedMail;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Finding revoked certificates, and recording them.
 *
 * The CA publishes a revocation in its CRL (Security Features Implementation
 * Standard v1.2, section 2.1.3, step 4) and "taxpayers and other relevant
 * stakeholders can check the certificate revocation status through the CA
 * publications (CRL/OCSP)" (step 5); the diagram there has the EGS read the
 * CRL periodically. sweep() is that periodic read, for every certificate a
 * device signs with or still files with. A renewal through the taxpayer
 * portal revokes the old certificate (section 2.1.2, step 4), so a
 * superseded version is checked too.
 *
 * A revoked version is marked with CredentialStore::revoke(): nothing is filed
 * with it again and, if the device signs with it, signing stops. The
 * organization's administrators are told by mail, each in their own
 * language, and the platform's operators through the alerts channel.
 */
class CertificateRevocations
{
    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly RevocationCheck $revocation,
        private readonly RevocationApprovals $approvals,
        private readonly ThemeService $themes,
        private readonly AuditService $audit,
    ) {}

    /**
     * Check every active and superseded version against its CA's list.
     *
     * @return Collection<int, array{version: CertificateVersion, state: string}>
     */
    public function sweep(?string $organizationId = null): Collection
    {
        $versions = CertificateVersion::withoutTenantScope(fn () => CertificateVersion::query()
            ->whereIn('status', [CertificateVersion::ACTIVE, CertificateVersion::SUPERSEDED])
            ->when($organizationId !== null, fn ($query) => $query->where('org_id', $organizationId))
            ->orderBy('org_id')->orderBy('device_id')->orderBy('version')
            ->get());

        return $versions->map(function (CertificateVersion $version): array {
            $record = $this->credentials->record($version->org_id, $this->branchOf($version), $version->certificate_id);
            $certificate = CertificatePem::from($record[CredentialStore::PCSID] ?? null);

            if ($certificate === null) {
                return ['version' => $version, 'state' => RevocationCheck::UNKNOWN];
            }

            $status = $this->revocation->status($certificate);

            if ($status['state'] === RevocationCheck::REVOKED) {
                $this->record($version, __('Published as revoked in the CA\'s revocation list.'), null, 'revocation_list');
            }

            return ['version' => $version->fresh() ?? $version, 'state' => $status['state']];
        });
    }

    /**
     * A platform admin marking a version revoked - ZATCA confirmed it, or the
     * key is known to be compromised - before the CA's list says so.
     *
     * @throws DomainException when the version is already revoked
     */
    public function markRevoked(CertificateVersion $version, string $reason, ?string $adminId): bool
    {
        if ($version->status === CertificateVersion::REVOKED) {
            throw new DomainException(__('This certificate is already recorded as revoked.'));
        }

        if (! $this->approvals->approve($version, $reason, $adminId)) {
            return false;
        }

        $this->record($version, $reason, $adminId, 'admin');

        return true;
    }

    private function record(CertificateVersion $version, string $reason, ?string $adminId, string $source): void
    {
        $wasActive = $version->status === CertificateVersion::ACTIVE;

        $this->credentials->revoke($version->org_id, $this->branchOf($version), $version->certificate_id);

        $this->audit->logAdminAction('zatca.certificate.revoked', 'CertificateVersion', (string) $version->getKey(),
            ['status' => $version->status],
            ['status' => CertificateVersion::REVOKED],
            ['org_id' => $version->org_id, 'device_id' => $version->device_id, 'version' => $version->version,
                'source' => $source, 'reason' => $reason, 'by' => $adminId, 'stopped_signing' => $wasActive],
        );

        // The platform's operators, through the alerts channel.
        Log::critical('A tenant device certificate was revoked'.($wasActive ? '; the device cannot sign until it is onboarded again' : ''), [
            'org_id' => $version->org_id,
            'device_id' => $version->device_id,
            'version' => $version->version,
            'source' => $source,
        ]);

        $this->tellAdministrators($version, $wasActive);
    }

    private function tellAdministrators(CertificateVersion $version, bool $stoppedSigning): void
    {
        $organization = Organization::query()->find($version->org_id);

        if ($organization === null) {
            return;
        }

        $brand = $this->themes->forScope((string) $organization->id);

        // One mail each, so each is written in its reader's language.
        foreach ($organization->users()->wherePivot('role', 'admin')->wherePivot('status', 'active')->get() as $admin) {
            Mail::to($admin)->send(new CertificateRevokedMail(
                organizationName: (string) $organization->name,
                version: $version->version,
                serialNumber: (string) $version->serial_number,
                stoppedSigning: $stoppedSigning,
                brand: $brand,
            ));
        }
    }

    private function branchOf(CertificateVersion $version): ?string
    {
        return $version->device_id === $version->org_id ? null : (string) $version->device_id;
    }
}
