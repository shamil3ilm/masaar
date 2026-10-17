<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\SigningException;
use Illuminate\Support\Facades\Log;

/**
 * The certificates between a device certificate and ZATCA's trust anchor.
 *
 * ZATCA's Security Features standard (2.2.1 item 17, and SignedData.certificates
 * in the PAdES structure) asks a stamp to carry the full chain up to the
 * anchor. Onboarding returns only the device's own certificate, so the CA
 * certificates come from a PEM bundle the operator configures
 * (ZATCA_CA_CHAIN_PATH). Unset, a stamp carries the device certificate alone,
 * as it did before.
 *
 * The path is built rather than the bundle copied: a certificate goes in only
 * when its subject is the previous one's issuer and its key verifies the
 * previous one's signature. A bundle holding another environment's CAs then
 * contributes nothing rather than a chain that leads somewhere else.
 */
class CertificateChain
{
    /** Bounded, so a bundle whose certificates issue each other ends. */
    private const MAX_DEPTH = 8;

    /**
     * @return list<string> PEM certificates, the issuer of the given one first
     *
     * @throws SigningException when a bundle is configured but cannot be read
     */
    public function above(string $certificatePem): array
    {
        $path = (string) config('fatoora.certificate.chain_path');

        if ($path === '') {
            return [];
        }

        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new SigningException("The certificate chain file {$path} cannot be read.");
        }

        $bundle = $this->certificates($contents);
        $chain = [];
        $current = $certificatePem;

        for ($depth = 0; $depth < self::MAX_DEPTH && ! $this->selfIssued($current); $depth++) {
            $issuer = $this->issuerOf($current, $bundle);

            if ($issuer === null) {
                // Not an error to stop the stamp over: the device certificate
                // still verifies it. Logged, because the chain the standard
                // asks for is then incomplete.
                Log::warning('The configured certificate chain does not reach a trust anchor.', [
                    'path' => $path,
                    'found' => count($chain),
                ]);

                break;
            }

            $chain[] = $issuer;
            $current = $issuer;
        }

        return $chain;
    }

    /**
     * @param  list<string>  $bundle
     */
    private function issuerOf(string $certificatePem, array $bundle): ?string
    {
        $issuer = openssl_x509_parse($certificatePem)['issuer'] ?? null;

        foreach ($bundle as $candidate) {
            $key = openssl_pkey_get_public($candidate);

            if ($key !== false
                && (openssl_x509_parse($candidate)['subject'] ?? null) === $issuer
                && openssl_x509_verify($certificatePem, $key) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    private function selfIssued(string $certificatePem): bool
    {
        $parsed = openssl_x509_parse($certificatePem);

        return $parsed !== false && $parsed['subject'] === $parsed['issuer'];
    }

    /**
     * @return list<string>
     */
    private function certificates(string $bundle): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $bundle, $matches);

        return $matches[0];
    }
}
