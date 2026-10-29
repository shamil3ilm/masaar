<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\SigningException;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Support\Errors\ErrorCode;
use App\Support\SafeFetch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use phpseclib3\File\X509;

/**
 * Whether a device certificate has been revoked, from the CA's revocation
 * list.
 *
 * "Before using the certificate for stamping, EGS/ZATCA e-invoicing platform
 * shall check certificate's validity against any of the above mentioned
 * sources" - the CRLs or OCSP responders the CA publishes - and "CRLs shall be
 * valid for seven (7) days which would allow EGSs to work fully offline for
 * seven (7) days" (ZATCA Electronic Invoice Security Features Implementation
 * Standard v1.2, section 2.2.1, requirement 14).
 *
 * What is checked: the device certificate that stamps. Requirement 14 names
 * that certificate; path validation up the chain is the verifier's, as of the
 * signing time (requirement 17).
 *
 * Where: the CRL distribution points the certificate names (section 2.2.2
 * lists them in the profile, as HTTP URLs), and the operator's
 * fatoora.certificate.crl_urls when it names none. OCSP is not used: the
 * profile marks it optional, the requirement is satisfied by either source,
 * and an OCSP answer is only evidence once the responder's signature is
 * verified, which nothing here does yet.
 *
 * Trust: a list counts only if it was issued by the certificate's issuer and,
 * when that CA certificate is known (ZATCA_CA_CHAIN_PATH), its signature
 * verifies against it. Without the CA certificate only a list fetched over
 * https counts, because nothing else would tell it from a forged one.
 *
 * Caching: a list is refreshed hourly while it can be, and relied on for as
 * long as the standard lets it be - up to its next update and never more than
 * seven days after it was issued. So a lost connection does not stop
 * stamping; seven days without one does.
 */
class RevocationCheck
{
    public const GOOD = 'good';

    public const REVOKED = 'revoked';

    /** The certificate names no list and none is configured. */
    public const NO_SOURCE = 'no_source';

    /** Lists exist, and none current could be obtained. */
    public const UNKNOWN = 'unknown';

    /** How often a held list is refreshed while the CA is reachable. */
    private const REFRESH_SECONDS = 3600;

    /** How long a failed download is left before trying again. */
    private const RETRY_SECONDS = 300;

    private const CACHE_PREFIX = 'zatca:crl:';

    public function __construct(private readonly CertificateChain $chain) {}

    /**
     * @return array{state: string, source: ?string, issued_at: ?string, revoked_at: ?string}
     */
    public function status(string $certificatePem, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $sources = $this->sources($certificatePem);

        if ($sources === []) {
            return ['state' => self::NO_SOURCE, 'source' => null, 'issued_at' => null, 'revoked_at' => null];
        }

        $serial = (string) (openssl_x509_parse($certificatePem)['serialNumberHex'] ?? '');
        $issuerDn = $this->issuerDn($certificatePem);
        $issuerPem = $this->issuerCertificate($certificatePem);

        foreach ($sources as $url) {
            $list = $this->list($url, $issuerPem, $issuerDn, $at);

            if ($list === null || $serial === '') {
                continue;
            }

            $revokedAt = $list->revocationOf($serial);

            return [
                'state' => $revokedAt === null ? self::GOOD : self::REVOKED,
                'source' => $url,
                'issued_at' => $list->thisUpdate->toIso8601String(),
                'revoked_at' => $revokedAt === '' ? null : $revokedAt,
            ];
        }

        return ['state' => self::UNKNOWN, 'source' => null, 'issued_at' => null, 'revoked_at' => null];
    }

    /**
     * Refuse to stamp with a revoked certificate, or with one whose status
     * no current list can vouch for.
     *
     * Fails closed on UNKNOWN: the standard allows seven days on a held list
     * and no more. A list is fetched within security.fetch.timeout_seconds,
     * so an unreachable CA costs a bounded wait, then this refusal.
     *
     * In ZATCA's production environment it fails closed on NO_SOURCE too:
     * nothing to check against is not a check, and a production stamp has
     * legal weight (requirement 14 applies "before using the certificate for
     * stamping"). The document stays a draft - nothing is numbered or
     * signed - with CERT_REVOCATION_UNVERIFIED as the reason, and the alerts
     * channel is told. The off switch (fatoora.features.certificate_
     * revocation_check) is refused there and logged. Outside production -
     * the developer portal and simulation, rehearsals as RevocationReadiness
     * counts them - NO_SOURCE stamps with a logged warning and the switch
     * still works.
     *
     * @param  string|null  $environment  the ZATCA environment the stamp is for (FatooraEnvironment); null is not production
     *
     * @throws FatooraException
     */
    public function assertMayStamp(string $certificatePem, ?string $environment = null): void
    {
        $production = $environment === FatooraEnvironment::PRODUCTION;

        if (! config('fatoora.features.certificate_revocation_check', true)) {
            if (! $production) {
                return;
            }

            Log::critical('Certificate revocation checking is switched off, which production does not allow; checked anyway.');
        }

        $status = $this->status($certificatePem);

        if ($status['state'] === self::REVOKED) {
            throw new FatooraException(
                __("This device's ZATCA certificate has been revoked, so it cannot sign invoices. Onboard the device again to obtain a new certificate."),
                ErrorCode::CERT_REVOKED,
                ['revoked_at' => $status['revoked_at'], 'source' => $status['source']],
            );
        }

        if ($status['state'] === self::UNKNOWN) {
            if ($production) {
                Log::critical('Production stamping held: no current certificate revocation list could be obtained.', ['sources' => $this->sources($certificatePem)]);
            }

            throw new FatooraException(
                __('The ZATCA certificate revocation list could not be obtained, and the last one held is more than seven days old or missing. Invoices cannot be signed until it can be checked again.'),
                ErrorCode::CERT_REVOCATION_UNVERIFIED,
                ['sources' => $this->sources($certificatePem)],
            );
        }

        if ($status['state'] === self::NO_SOURCE) {
            if (! $production) {
                Log::warning('Certificate stamped without a revocation check: it names no revocation list and none is configured (allowed outside production).');

                return;
            }

            Log::critical('Production stamping held: the certificate names no revocation list and ZATCA_CRL_URLS gives none.');

            throw new FatooraException(
                __("This device's certificate names no revocation list and none is configured, so it cannot be checked before it signs. Invoices are held until the platform configures one."),
                ErrorCode::CERT_REVOCATION_UNVERIFIED,
                ['sources' => []],
            );
        }
    }

    /**
     * @return list<string>
     */
    public function sources(string $certificatePem): array
    {
        $named = (string) (openssl_x509_parse($certificatePem)['extensions']['crlDistributionPoints'] ?? '');
        preg_match_all('#URI:(https?://\S+)#i', $named, $found);

        $urls = $found[1] !== []
            ? $found[1]
            : array_values(array_filter((array) config('fatoora.certificate.crl_urls', [])));

        return array_values(array_unique(array_filter(
            array_map('trim', $urls),
            fn (string $url): bool => $this->permitted($url)
        )));
    }

    /**
     * A current list from $url: the cached one when fresh, a new download
     * when due, the cached one again when the download fails and it is
     * still usable.
     */
    private function list(string $url, ?string $issuerPem, string $issuerDn, CarbonImmutable $at): ?RevocationList
    {
        $key = self::CACHE_PREFIX.sha1($url);
        $held = Cache::get($key);
        $cached = is_array($held) ? $this->accepted((string) base64_decode((string) $held['crl']), $url, $issuerPem, $issuerDn) : null;

        $due = $cached === null || ($at->getTimestamp() - (int) $held['fetched_at']) >= self::REFRESH_SECONDS;

        if ($due && ! Cache::has($key.':failed')) {
            $fresh = $this->download($url);
            $list = $fresh === null ? null : $this->accepted($fresh, $url, $issuerPem, $issuerDn);

            if ($list !== null) {
                Cache::forever($key, ['crl' => base64_encode($fresh), 'fetched_at' => $at->getTimestamp()]);
                $cached = $list;
            } else {
                Cache::put($key.':failed', true, self::RETRY_SECONDS);
            }
        }

        return $cached !== null && $cached->usableAt($at) ? $cached : null;
    }

    private function accepted(string $bytes, string $url, ?string $issuerPem, string $issuerDn): ?RevocationList
    {
        $list = RevocationList::parse($bytes);

        if ($list === null || $list->issuer !== $issuerDn) {
            return null;
        }

        if ($issuerPem !== null) {
            return $list->signedBy($issuerPem) ? $list : null;
        }

        return str_starts_with(strtolower($url), 'https://') ? $list : null;
    }

    /**
     * Through SafeFetch, the platform's hardened fetch: connected to the
     * address SafeUrl approved and no other, no redirects, and abandoned
     * while it is read once it passes the size limit. An http list too - the
     * profile publishes lists at HTTP URLs (section 2.2.2) - under the same
     * host rules, and accepted() then takes it only if its signature
     * verifies against the issuing CA.
     */
    private function download(string $url): ?string
    {
        return SafeFetch::get($url, 'security.revocation_hosts', plainHttp: true);
    }

    /**
     * http or https. Which hosts is SafeUrl's to say, at download.
     */
    private function permitted(string $url): bool
    {
        $parts = parse_url($url);

        return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }

    private function issuerDn(string $certificatePem): string
    {
        $x509 = new X509;

        return $x509->loadX509($certificatePem) === false ? '' : (string) $x509->getIssuerDN(X509::DN_STRING);
    }

    /**
     * The issuing CA's certificate from the configured chain, or null.
     */
    private function issuerCertificate(string $certificatePem): ?string
    {
        try {
            return $this->chain->above($certificatePem)[0] ?? null;
        } catch (SigningException) {
            return null;
        }
    }
}
