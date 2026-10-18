<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use Carbon\CarbonImmutable;
use phpseclib3\File\X509;
use phpseclib3\Math\BigInteger;

/**
 * One certificate revocation list, as the CA published it.
 *
 * Parsed with phpseclib, DER or PEM. A list is only evidence once its
 * signature verifies against the certificate of the CA that issued it -
 * signedBy() - and only while it is current - usableAt().
 */
final class RevocationList
{
    /**
     * The longest a list stands in for a fresh one: "CRLs shall be valid for
     * seven (7) days" (ZATCA Electronic Invoice Security Features
     * Implementation Standard v1.2, section 2.2.1, requirement 14).
     */
    public const MAX_AGE_DAYS = 7;

    /**
     * @param  array<string, string|null>  $revoked  normalised serial => revocation date
     */
    private function __construct(
        private readonly string $bytes,
        public readonly string $issuer,
        public readonly CarbonImmutable $thisUpdate,
        public readonly ?CarbonImmutable $nextUpdate,
        private readonly array $revoked,
    ) {}

    /**
     * Null for anything that is not a readable list: an HTML error page, a
     * truncated download, a list with no issue date.
     */
    public static function parse(string $bytes): ?self
    {
        $x509 = new X509;

        try {
            $crl = $x509->loadCRL($bytes);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($crl)) {
            return null;
        }

        $thisUpdate = self::time($crl['tbsCertList']['thisUpdate'] ?? null);

        if ($thisUpdate === null) {
            return null;
        }

        $revoked = [];

        foreach ($x509->listRevoked() ?: [] as $decimalSerial) {
            $entry = $x509->getRevoked($decimalSerial);
            $revoked[self::serial((string) $decimalSerial, 10)] = $entry['revocationDate']['utcTime']
                ?? $entry['revocationDate']['generalTime']
                ?? null;
        }

        return new self(
            $bytes,
            (string) $x509->getIssuerDN(X509::DN_STRING),
            $thisUpdate,
            self::time($crl['tbsCertList']['nextUpdate'] ?? null),
            $revoked,
        );
    }

    /**
     * Whether the CA certificate given issued and signed this list.
     */
    public function signedBy(string $issuerPem): bool
    {
        $x509 = new X509;

        try {
            if ($x509->loadCA($issuerPem) === false || $x509->loadCRL($this->bytes) === false) {
                return false;
            }

            return $x509->validateSignature() === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the list may be relied on at $at: issued, not past its own next
     * update, and no older than the seven days the standard allows.
     */
    public function usableAt(CarbonImmutable $at): bool
    {
        $until = $this->thisUpdate->addDays(self::MAX_AGE_DAYS);

        if ($this->nextUpdate !== null && $this->nextUpdate->lessThan($until)) {
            $until = $this->nextUpdate;
        }

        return ! $at->lessThan($this->thisUpdate->subMinutes(5)) && $at->lessThan($until);
    }

    /**
     * When the certificate with this serial was revoked, or null when the
     * list does not name it.
     *
     * @param  string  $serialHex  as openssl_x509_parse() reports it
     */
    public function revocationOf(string $serialHex): ?string
    {
        $serial = self::serial($serialHex, 16);

        return array_key_exists($serial, $this->revoked) ? ($this->revoked[$serial] ?? '') : null;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * One comparable form: uppercase hex, no 0x, no leading zeros. The base
     * is stated, never guessed - "1000" is valid in both, and reading the
     * certificate's hex as decimal reports a revoked certificate as good.
     *
     * @param  int  $base  16 for a certificate serial, 10 for a list entry
     */
    public static function serial(string $serial, int $base): string
    {
        $serial = (string) preg_replace('/^0x/i', '', trim($serial));

        if ($base === 10) {
            $serial = (new BigInteger($serial, 10))->toHex();
        }

        $serial = ltrim(strtoupper($serial), '0');

        return $serial === '' ? '0' : $serial;
    }

    /**
     * @param  array<string, string>|null  $time
     */
    private static function time(?array $time): ?CarbonImmutable
    {
        $value = $time['utcTime'] ?? $time['generalTime'] ?? null;

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
