<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\Der;
use App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime;
use Illuminate\Support\Facades\Log;

/**
 * Certificate management service.
 *
 * Reads, loads and verifies ZATCA certificates, including their expiry and
 * revocation. Certificate requests are CsrBuilder's.
 */
class CertificateService
{
    public function __construct(private readonly RevocationCheck $revocation) {}

    /**
     * Parse certificate and extract details.
     *
     * @param  string  $certificatePem  PEM-encoded certificate
     * @return array Certificate details
     *
     * @throws CertificateException
     */
    public function parseCertificate(string $certificatePem): array
    {
        $cert = openssl_x509_read($certificatePem);

        if ($cert === false) {
            throw new CertificateException('Invalid certificate: '.openssl_error_string());
        }

        $details = openssl_x509_parse($cert);

        if ($details === false) {
            throw new CertificateException('Could not parse certificate');
        }

        return [
            'subject' => $details['subject'] ?? [],
            'issuer' => $details['issuer'] ?? [],
            'validFrom' => gmdate('Y-m-d H:i:s', $details['validFrom_time_t'] ?? 0),
            'validTo' => gmdate('Y-m-d H:i:s', $details['validTo_time_t'] ?? 0),
            'validFromUtc' => FatooraTime::fromUnixTimestamp($details['validFrom_time_t'] ?? 0),
            'validToUtc' => FatooraTime::fromUnixTimestamp($details['validTo_time_t'] ?? 0),
            'serialNumber' => $details['serialNumber'] ?? null,
            // X.509 serials are up to 20 octets (160 bits), so they do not fit
            // in a PHP int. Always compare using this hex form, never by
            // casting serialNumber to a number.
            'serialNumberHex' => $details['serialNumberHex'] ?? null,
            'extensions' => $details['extensions'] ?? [],
        ];
    }

    /**
     * Check if certificate is valid (not expired).
     *
     * @param  string  $certificatePem  PEM-encoded certificate
     * @return bool True if valid
     */
    public function isValid(string $certificatePem): bool
    {
        try {
            $details = $this->parseCertificate($certificatePem);
            $validTo = strtotime($details['validTo']);

            return $validTo > time();
        } catch (CertificateException) {
            return false;
        }
    }

    /**
     * Get certificate expiry date in UTC.
     *
     * @param  string  $certificatePem  PEM-encoded certificate
     */
    public function getExpiryDate(string $certificatePem): ?\DateTimeImmutable
    {
        try {
            $details = $this->parseCertificate($certificatePem);

            // Use the UTC DateTimeImmutable directly
            return $details['validToUtc'] ?? new \DateTimeImmutable($details['validTo'], new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            Log::warning('Certificate expiry could not be read', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract signature from certificate (for QR code tag 9).
     *
     * Parses the X.509 certificate ASN.1 structure to extract
     * the digital signature value.
     *
     * @param  string  $certificatePem  PEM-encoded certificate
     * @return string Raw signature bytes (not base64 encoded)
     *
     * @throws CertificateException
     */
    public function getCertificateSignature(string $certificatePem): string
    {
        $der = $this->pemToDer($certificatePem);

        try {
            // Parse the certificate ASN.1 structure
            // Certificate ::= SEQUENCE {
            //     tbsCertificate       TBSCertificate,
            //     signatureAlgorithm   AlgorithmIdentifier,
            //     signatureValue       BIT STRING
            // }
            $signature = $this->extractSignatureFromDer($der);

            return $signature;
        } catch (\Exception $e) {
            throw new CertificateException('Failed to extract certificate signature: '.$e->getMessage());
        }
    }

    /**
     * Extract signature from DER-encoded certificate using ASN.1 parsing.
     */
    private function extractSignatureFromDer(string $der): string
    {
        $offset = 0;
        $length = strlen($der);

        // Parse outer SEQUENCE (Certificate)
        if (ord($der[$offset]) !== 0x30) {
            throw new CertificateException('Invalid certificate: expected SEQUENCE');
        }
        $offset++;

        // Skip outer SEQUENCE length
        $offset = Der::skipLength($der, $offset);

        // Skip tbsCertificate SEQUENCE
        if (ord($der[$offset]) !== 0x30) {
            throw new CertificateException('Invalid certificate: expected tbsCertificate SEQUENCE');
        }
        $offset++;
        $tbsLength = Der::readLength($der, $offset);
        $offset = Der::skipLength($der, $offset);
        $offset += $tbsLength;

        // Skip signatureAlgorithm SEQUENCE
        if (ord($der[$offset]) !== 0x30) {
            throw new CertificateException('Invalid certificate: expected signatureAlgorithm SEQUENCE');
        }
        $offset++;
        $algLength = Der::readLength($der, $offset);
        $offset = Der::skipLength($der, $offset);
        $offset += $algLength;

        // Read signatureValue BIT STRING
        if (ord($der[$offset]) !== 0x03) {
            throw new CertificateException('Invalid certificate: expected signatureValue BIT STRING');
        }
        $offset++;

        $sigLength = Der::readLength($der, $offset);
        $offset = Der::skipLength($der, $offset);

        // Skip the unused bits byte (first byte of BIT STRING content)
        $offset++;
        $sigLength--;

        // Extract the signature bytes
        return substr($der, $offset, $sigLength);
    }

    /**
     * Convert PEM to DER format.
     */
    private function pemToDer(string $pem): string
    {
        $lines = explode("\n", $pem);
        $base64 = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '-----') === false && ! empty($line)) {
                $base64 .= $line;
            }
        }

        return base64_decode($base64);
    }

    /**
     * Check certificate revocation status against the issuer's CRL.
     *
     * RevocationCheck decides, so the check before filing reads the same
     * lists, with the same trust and the same seven-day limit, as the check
     * before stamping. A list that cannot be had is reported as a warning,
     * not as revoked: the stamp was already checked when it was applied.
     *
     * @param  string  $certificatePem  PEM-encoded certificate to check
     * @return array{revoked: bool, method: string, reason: string|null, revokedAt: string|null, warning?: string}
     */
    public function checkRevocationStatus(string $certificatePem): array
    {
        $status = $this->revocation->status($certificatePem);

        return match ($status['state']) {
            RevocationCheck::REVOKED => ['revoked' => true, 'method' => 'crl', 'reason' => 'Certificate found in CRL', 'revokedAt' => $status['revoked_at']],
            RevocationCheck::GOOD => ['revoked' => false, 'method' => 'crl', 'reason' => null, 'revokedAt' => null],
            RevocationCheck::NO_SOURCE => ['revoked' => false, 'method' => 'none', 'reason' => null, 'revokedAt' => null,
                'warning' => 'Certificate advertises no CRL distribution point and none is configured'],
            default => ['revoked' => false, 'method' => 'none', 'reason' => null, 'revokedAt' => null,
                'warning' => 'No current revocation list could be obtained'],
        };
    }

    /**
     * Get days until certificate expiry.
     *
     * @param  string  $certificatePem  PEM-encoded certificate
     * @return int|null Days until expiry, negative if expired, null on error
     */
    public function getDaysUntilExpiry(string $certificatePem): ?int
    {
        $expiryDate = $this->getExpiryDate($certificatePem);

        if ($expiryDate === null) {
            return null;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diff = $now->diff($expiryDate);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * What the admin screens show about a certificate.
     *
     * Read from the certificate itself rather than from a row describing it,
     * so the dates and serial on screen are the ones ZATCA will see.
     *
     * @return array{serial_number: ?string, valid_from: string, valid_to: string, status: string}|null
     */
    public function details(?string $certificatePem): ?array
    {
        if ($certificatePem === null) {
            return null;
        }

        try {
            $parsed = $this->parseCertificate($certificatePem);
        } catch (CertificateException) {
            return null;
        }

        return [
            'serial_number' => $parsed['serialNumberHex'] ?? $parsed['serialNumber'],
            'valid_from' => $parsed['validFrom'],
            'valid_to' => $parsed['validTo'],
            'status' => $this->status($certificatePem)['status'],
        ];
    }

    /**
     * How a certificate is doing, for the dashboards to display.
     *
     * One set of bands rather than one per caller: a certificate reported as
     * healthy on one screen and critical on another is a question nobody can
     * answer. Null means the organization has not onboarded.
     *
     * @return array{status: string, message?: string, days_remaining?: int, expired_days_ago?: int}
     */
    public function status(?string $certificatePem): array
    {
        if ($certificatePem === null) {
            return ['status' => 'missing', 'message' => 'No active certificate found'];
        }

        $days = $this->getDaysUntilExpiry($certificatePem);

        if ($days === null) {
            return ['status' => 'unknown', 'message' => 'Certificate expiry could not be read'];
        }

        if ($days < 0) {
            return [
                'status' => 'expired',
                'message' => 'Certificate has expired',
                'expired_days_ago' => abs($days),
            ];
        }

        $band = match (true) {
            $days <= 7 => 'critical',
            $days <= 30 => 'warning',
            default => 'healthy',
        };

        return [
            'status' => $band,
            'message' => "Certificate expires in {$days} days",
            'days_remaining' => $days,
        ];
    }

    /**
     * Validate certificate for pre-submission checks.
     *
     * Returns detailed validation results including:
     * - Expiry status and days remaining
     * - Revocation status
     * - Validity for signing
     *
     * @param  string  $certificatePem  PEM-encoded certificate
     * @return array{valid: bool, errors: array, warnings: array, days_until_expiry: int|null}
     */
    public function validateForSubmission(string $certificatePem): array
    {
        $errors = [];
        $warnings = [];
        $daysUntilExpiry = $this->getDaysUntilExpiry($certificatePem);

        // Check if certificate is valid
        if (! $this->isValid($certificatePem)) {
            $errors[] = 'CERT_EXPIRED: Certificate has expired and cannot be used for signing';
        }

        // Warning thresholds from config
        $warningDays = config('fatoora.certificate.expiry_warning_days', 30);
        $criticalDays = config('fatoora.certificate.expiry_critical_days', 7);

        // Check expiry warnings
        if ($daysUntilExpiry !== null && $daysUntilExpiry > 0) {
            if ($daysUntilExpiry <= $criticalDays) {
                $warnings[] = "CERT_EXPIRING_CRITICAL: Certificate expires in {$daysUntilExpiry} days - immediate renewal required";
            } elseif ($daysUntilExpiry <= $warningDays) {
                $warnings[] = "CERT_EXPIRING_WARNING: Certificate expires in {$daysUntilExpiry} days - schedule renewal";
            }
        }

        // Check revocation status if enabled
        if (config('fatoora.features.certificate_revocation_check', true)) {
            try {
                $revocationStatus = $this->checkRevocationStatus($certificatePem);

                if ($revocationStatus['revoked']) {
                    $errors[] = 'CERT_REVOKED: Certificate has been revoked and cannot be used';
                } elseif (isset($revocationStatus['warning'])) {
                    // Reaching no OCSP or CRL endpoint is not the same as being
                    // told the certificate is good. Surface it rather than
                    // letting an unperformed check read as a pass.
                    $warnings[] = 'CERT_REVOCATION_UNVERIFIED: '.$revocationStatus['warning'];
                }
            } catch (\Throwable $e) {
                $warnings[] = 'CERT_REVOCATION_CHECK_FAILED: Could not verify revocation status';
                Log::warning('Certificate revocation check failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'days_until_expiry' => $daysUntilExpiry,
        ];
    }
}
