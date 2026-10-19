<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use Carbon\CarbonImmutable;

/**
 * Which certificate a stored credential, or a signed document, carries.
 *
 * id is the digest ChainRecorder records for every document: SHA-256 of the
 * certificate as the signing path hands it over, which is CertificatePem of
 * the stored token. Computed the same way here, a chain entry names the
 * credential that signed it.
 *
 * Serial and issuer identify a certificate whatever form it was stored in,
 * which is what a document with no chain entry is matched on. Both, and the
 * validity window, are null for a token OpenSSL cannot read.
 */
final readonly class CertificateIdentity
{
    private function __construct(
        public string $id,
        public ?string $serialNumber,
        public ?string $issuer,
        public ?CarbonImmutable $validFrom,
        public ?CarbonImmutable $validTo,
    ) {}

    /**
     * @param  string  $stored  A CSID as stored: ZATCA's token, or a PEM
     */
    public static function of(string $stored): self
    {
        $pem = (string) CertificatePem::from($stored);
        $parsed = self::parse($pem);

        return new self(
            hash('sha256', $pem),
            $parsed === null ? null : (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
            $parsed === null ? null : self::issuer($parsed),
            isset($parsed['validFrom_time_t']) ? CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t']) : null,
            isset($parsed['validTo_time_t']) ? CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']) : null,
        );
    }

    /**
     * The certificate a signed document carries in its KeyInfo, or null.
     */
    public static function ofDocument(?string $signedXml): ?self
    {
        if ($signedXml === null || preg_match('#<ds:X509Certificate>([^<]+)</ds:X509Certificate>#', $signedXml, $found) !== 1) {
            return null;
        }

        return self::of(trim($found[1]));
    }

    /**
     * Whether two identities are the same certificate.
     *
     * By digest when they agree, by serial and issuer otherwise: the same
     * certificate stored as a PEM and carried in a document as bare base64
     * digests differently.
     */
    public function sameAs(self $other): bool
    {
        if ($this->id === $other->id) {
            return true;
        }

        return $this->serialNumber !== null && $this->serialNumber !== ''
            && $this->serialNumber === $other->serialNumber
            && $this->issuer === $other->issuer;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parse(string $pem): ?array
    {
        $certificate = @openssl_x509_read($pem);

        if ($certificate === false) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate);

        return $parsed === false ? null : $parsed;
    }

    /**
     * The issuer's distinguished name, in the order the certificate gives it.
     *
     * @param  array<string, mixed>  $parsed
     */
    private static function issuer(array $parsed): string
    {
        $parts = [];

        foreach ((array) ($parsed['issuer'] ?? []) as $name => $value) {
            foreach ((array) $value as $one) {
                $parts[] = $name.'='.$one;
            }
        }

        return implode(', ', $parts);
    }
}
