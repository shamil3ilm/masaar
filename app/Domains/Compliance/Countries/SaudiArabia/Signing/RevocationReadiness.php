<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use Illuminate\Support\Facades\Cache;

/**
 * What a production instance needs before RevocationCheck can do its job.
 *
 * "Before using the certificate for stamping, EGS/ZATCA e-invoicing platform
 * shall check certificate's validity" against the CA's CRLs (Security
 * Features Implementation Standard v1.2, section 2.2.1, requirement 14).
 * RevocationCheck fails open when a certificate names no list and
 * ZATCA_CRL_URLS gives none, and trusts an http list only once it verifies
 * against the CA certificate (ZATCA_CA_CHAIN_PATH). Either gap is a
 * configuration to fix, so readiness reports it rather than every stamp.
 *
 * Every active device certificate is read for its distribution points; the
 * answer is kept for a few minutes, since a load balancer asks often.
 */
class RevocationReadiness
{
    private const CACHE_KEY = 'zatca:readiness:unchecked_certificates';

    private const CACHE_SECONDS = 300;

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly RevocationCheck $revocation,
    ) {}

    /**
     * Empty when ready.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        // Only invoices stamped for ZATCA's production gateway carry legal
        // weight. A deployment still on the simulation or sandbox gateway
        // is a rehearsal, and is often stood up before the operator holds
        // the CA chain - refusing it traffic would block the rehearsal, not
        // protect an invoice.
        if (config('fatoora.environment') !== 'production') {
            return [];
        }

        $problems = [];
        $chain = (string) config('fatoora.certificate.chain_path');

        if ($chain === '' || ! is_readable($chain)) {
            $problems[] = 'ZATCA_CA_CHAIN_PATH does not name a readable file, so a revocation list served over http cannot be verified before a certificate stamps.';
        }

        $unchecked = (int) Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): int => $this->withoutSource());

        if ($unchecked > 0) {
            $problems[] = $unchecked.' active device certificate(s) name no revocation list and ZATCA_CRL_URLS gives none, so nothing checks them before they stamp.';
        }

        return $problems;
    }

    /**
     * How many active certificates RevocationCheck would find no list for.
     */
    private function withoutSource(): int
    {
        return (int) CertificateVersion::withoutTenantScope(fn (): int => CertificateVersion::query()
            ->where('status', CertificateVersion::ACTIVE)
            ->lazyById()
            ->filter(function (CertificateVersion $version): bool {
                $branchId = $version->device_id === $version->org_id ? null : (string) $version->device_id;
                $record = $this->credentials->record((string) $version->org_id, $branchId, (string) $version->certificate_id);
                $certificate = CertificatePem::from($record[CredentialStore::PCSID] ?? null);

                return $certificate !== null && $this->revocation->sources($certificate) === [];
            })
            ->count());
    }
}
