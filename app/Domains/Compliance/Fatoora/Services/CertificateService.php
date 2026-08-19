<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use App\Domains\Compliance\Fatoora\Exceptions\CertificateException;
use App\Domains\Compliance\Fatoora\Helpers\FatooraTime;
use App\Support\SafeFetch;
use Illuminate\Support\Facades\Log;
use phpseclib3\File\X509;
use phpseclib3\Math\BigInteger;

/**
 * Certificate management service.
 *
 * Handles CSR generation, certificate storage, and CSID management
 * for ZATCA e-invoicing compliance.
 */
class CertificateService
{
    // ZATCA-specific OIDs
    private const OID_ORGANIZATION_IDENTIFIER = '2.5.4.97';

    private const OID_INVOICE_TYPE = '1.3.6.1.4.1.311.20.2';

    private const OID_ZATCA_REGISTERED_ADDRESS = '2.5.4.26';

    private const OID_ZATCA_BUSINESS_CATEGORY = '2.5.4.15';

    /**
     * Generate Certificate Signing Request (CSR).
     *
     * @param  CsrData  $data  CSR configuration data
     * @return array{csr: string, privateKey: string}
     *
     * @throws CertificateException
     */
    public function generateCsr(CsrData $data): array
    {
        // Create temporary OpenSSL config with ZATCA extensions
        $configFile = $this->createZatcaOpenSslConfig($data);

        try {
            // Generate ECDSA key pair
            $keyConfig = [
                'curve_name' => 'secp256k1',
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'config' => $configFile,
            ];

            $privateKey = openssl_pkey_new($keyConfig);

            if ($privateKey === false) {
                throw new CertificateException('Failed to generate private key: '.openssl_error_string());
            }

            // The subject, in full. openssl_csr_new() uses this array and
            // ignores the config's [dn] section entirely, so the
            // organizationIdentifier declared there never reached the request
            // — and ZATCA requires the VAT registration in the subject.
            $dn = [
                'C' => 'SA',
                'O' => $data->organizationName,
                'OU' => $data->organizationUnit,
                'CN' => $data->commonName,
                'organizationIdentifier' => $data->getOrganizationIdentifier(),
            ];

            // CSR configuration with custom config file
            $csrConfig = [
                'digest_alg' => 'sha256',
                'config' => $configFile,
                'req_extensions' => 'zatca_req_ext',
            ];

            // Generate CSR
            $csr = openssl_csr_new($dn, $privateKey, $csrConfig);

            if ($csr === false) {
                throw new CertificateException('Failed to generate CSR: '.openssl_error_string());
            }

            // Both exports take the same config the key was generated under,
            // and both are checked. openssl_pkey_export() leaves its output
            // untouched when it fails, so an unchecked call returns an empty
            // string as the private key: onboarding would store nothing,
            // receive a real CSID against it, and only fail later at the first
            // signature.
            $csrPem = '';

            if (! openssl_csr_export($csr, $csrPem)) {
                throw new CertificateException('Failed to export CSR: '.openssl_error_string());
            }

            $privateKeyPem = '';

            if (! openssl_pkey_export($privateKey, $privateKeyPem, null, ['config' => $configFile])) {
                throw new CertificateException(
                    'Failed to export private key: '.openssl_error_string()
                );
            }

            return [
                'csr' => $csrPem,
                'privateKey' => $privateKeyPem,
            ];
        } finally {
            // Clean up temporary config file
            if (file_exists($configFile)) {
                unlink($configFile);
            }
        }
    }

    /**
     * Create temporary OpenSSL config file with ZATCA extensions.
     */
    private function createZatcaOpenSslConfig(CsrData $data): string
    {
        // Format organization identifier per ZATCA spec
        $orgIdentifier = $this->formatOrganizationIdentifier($data->vatNumber);

        // Build SAN with ZATCA-required fields
        $directoryName = $this->buildDirectoryName($data);

        $config = <<<EOL
# ZATCA Compliant OpenSSL Configuration
oid_section = zatca_oids

[zatca_oids]
organizationIdentifier = 2.5.4.97
registeredAddress = 2.5.4.26
businessCategory = 2.5.4.15

[req]
default_bits = 2048
prompt = no
default_md = sha256
distinguished_name = dn
req_extensions = zatca_req_ext

[dn]
C = SA
O = {$data->organizationName}
OU = {$data->organizationUnit}
CN = {$data->commonName}
organizationIdentifier = {$orgIdentifier}
registeredAddress = {$data->location}
businessCategory = {$data->industry}

[zatca_req_ext]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, nonRepudiation
extendedKeyUsage = serverAuth, clientAuth
subjectAltName = @alt_names
1.3.6.1.4.1.311.20.2 = ASN1:PRINTABLESTRING:ZATCA-Code-Signing

[alt_names]
dirName = zatca_dir_name

[zatca_dir_name]
{$directoryName}
EOL;

        $tempFile = tempnam(sys_get_temp_dir(), 'zatca_csr_');
        file_put_contents($tempFile, $config);

        return $tempFile;
    }

    /**
     * Format organization identifier per ZATCA specification.
     * Format: VATSA-{15-digit VAT number}
     */
    private function formatOrganizationIdentifier(string $vatNumber): string
    {
        // Ensure VAT number is 15 digits
        $cleanVat = preg_replace('/[^0-9]/', '', $vatNumber);

        return 'VATSA-'.str_pad($cleanVat, 15, '0', STR_PAD_LEFT);
    }

    /**
     * The directory name ZATCA reads the device's identity out of.
     *
     * These are attributes of one X.501 directory name, so they belong in a
     * section that subjectAltName's `dirName` points at. They were written as
     * `dirName.1 = SN=...`, `dirName.2 = UID=...` and so on, which OpenSSL
     * reads as five directory names each naming a section — and none of those
     * sections exist. alt_names then failed to load, taking the whole
     * request-extensions section with it, so no CSR was produced at all.
     */
    private function buildDirectoryName(CsrData $data): string
    {
        $attributes = [
            'SN' => $data->serialNumber,
            'UID' => $data->vatNumber,
            // Four digits, one per document type ZATCA recognises. Positions
            // three and four are the standard and simplified invoices this
            // device is being registered for.
            'title' => '1'
                .($data->invoiceTypesStandard ? '1' : '0')
                .($data->invoiceTypesSimplified ? '1' : '0')
                .'0',
            'registeredAddress' => $data->location,
            'businessCategory' => $data->industry,
        ];

        $lines = [];

        foreach ($attributes as $key => $value) {
            if ($value !== '') {
                $lines[] = "{$key} = {$value}";
            }
        }

        return implode("\n", $lines);
    }

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
        } catch (\Exception) {
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
        $offset = $this->skipAsn1Length($der, $offset);

        // Skip tbsCertificate SEQUENCE
        if (ord($der[$offset]) !== 0x30) {
            throw new CertificateException('Invalid certificate: expected tbsCertificate SEQUENCE');
        }
        $offset++;
        $tbsLength = $this->readAsn1Length($der, $offset);
        $offset = $this->skipAsn1Length($der, $offset);
        $offset += $tbsLength;

        // Skip signatureAlgorithm SEQUENCE
        if (ord($der[$offset]) !== 0x30) {
            throw new CertificateException('Invalid certificate: expected signatureAlgorithm SEQUENCE');
        }
        $offset++;
        $algLength = $this->readAsn1Length($der, $offset);
        $offset = $this->skipAsn1Length($der, $offset);
        $offset += $algLength;

        // Read signatureValue BIT STRING
        if (ord($der[$offset]) !== 0x03) {
            throw new CertificateException('Invalid certificate: expected signatureValue BIT STRING');
        }
        $offset++;

        $sigLength = $this->readAsn1Length($der, $offset);
        $offset = $this->skipAsn1Length($der, $offset);

        // Skip the unused bits byte (first byte of BIT STRING content)
        $offset++;
        $sigLength--;

        // Extract the signature bytes
        return substr($der, $offset, $sigLength);
    }

    /**
     * Read ASN.1 length field.
     */
    private function readAsn1Length(string $data, int $offset): int
    {
        $byte = ord($data[$offset]);

        if ($byte < 0x80) {
            // Short form: length is in this byte
            return $byte;
        }

        // Long form: first byte indicates number of length bytes
        $numBytes = $byte & 0x7F;
        $length = 0;

        for ($i = 0; $i < $numBytes; $i++) {
            $length = ($length << 8) | ord($data[$offset + 1 + $i]);
        }

        return $length;
    }

    /**
     * Skip past ASN.1 length field.
     */
    private function skipAsn1Length(string $data, int $offset): int
    {
        $byte = ord($data[$offset]);

        if ($byte < 0x80) {
            return $offset + 1;
        }

        $numBytes = $byte & 0x7F;

        return $offset + 1 + $numBytes;
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
     * Load certificate from file.
     *
     * @param  string  $path  File path
     * @return string PEM-encoded certificate
     *
     * @throws CertificateException
     */
    public function loadFromFile(string $path): string
    {
        if (! file_exists($path)) {
            throw new CertificateException("Certificate file not found: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new CertificateException("Could not read certificate file: {$path}");
        }

        return $content;
    }

    /**
     * Load private key from file.
     *
     * @param  string  $path  File path
     * @param  string|null  $passphrase  Optional passphrase
     * @return string PEM-encoded private key
     *
     * @throws CertificateException
     */
    public function loadPrivateKey(string $path, ?string $passphrase = null): string
    {
        if (! file_exists($path)) {
            throw new CertificateException("Private key file not found: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new CertificateException("Could not read private key file: {$path}");
        }

        // Verify the key is valid
        $key = openssl_pkey_get_private($content, $passphrase);

        if ($key === false) {
            throw new CertificateException('Invalid private key: '.openssl_error_string());
        }

        return $content;
    }

    /**
     * Check certificate revocation status against the issuer's CRL.
     *
     * OCSP is not consulted, though certificates commonly advertise it and it
     * carries fresher status than a list published on a schedule. Answering an
     * OCSP query safely means verifying the responder's signature over the
     * response, and until that exists an answer cannot be distinguished from
     * one an attacker supplied.
     *
     * That is not a neutral gap. This method returned on the first OCSP answer
     * and never reached the CRL, so an unverified "good" suppressed the check
     * that would have found the certificate revoked. A slightly staler answer
     * that is actually the issuer's beats a fresh one from anybody.
     *
     * @param  string  $certificatePem  PEM-encoded certificate to check
     * @return array{revoked: bool, method: string, reason: string|null, revokedAt: string|null}
     *
     * @throws CertificateException
     */
    public function checkRevocationStatus(string $certificatePem): array
    {
        $details = $this->parseCertificate($certificatePem);
        $extensions = $details['extensions'] ?? [];

        $crlUrls = $this->extractCrlUrls($extensions);
        foreach ($crlUrls as $crlUrl) {
            try {
                $crlResult = $this->checkCrl($certificatePem, $crlUrl);
                if ($crlResult !== null) {
                    return $crlResult;
                }
            } catch (\Exception $e) {
                Log::warning('CRL check failed', [
                    'error' => $e->getMessage(),
                    'crl_url' => $crlUrl,
                ]);

                continue;
            }
        }

        // No revocation information available
        return [
            'revoked' => false,
            'method' => 'none',
            'reason' => null,
            'revokedAt' => null,
            'warning' => 'Certificate advertises no reachable CRL distribution point',
        ];
    }

    /**
     * Check certificate revocation via CRL (Certificate Revocation List).
     *
     * @param  string  $certificatePem  Certificate to check
     * @param  string  $crlUrl  CRL distribution point URL
     * @return array|null Revocation status or null if check failed
     */
    private function checkCrl(string $certificatePem, string $crlUrl): ?array
    {
        // The address came out of the certificate being checked, so the fetch
        // is bounded: allowlisted host, https, no redirects, capped size.
        $crlData = SafeFetch::get($crlUrl, 'security.revocation_hosts');

        if ($crlData === null) {
            return null;
        }

        // Get certificate serial number. The hex form is required: X.509
        // serials run to 20 octets and will not fit in a PHP int.
        $details = $this->parseCertificate($certificatePem);
        $serialHex = $details['serialNumberHex'] ?? null;

        if ($serialHex === null) {
            // Inconclusive, not "not revoked" — the caller must not read a
            // missing serial as a clean bill of health.
            Log::warning('CRL check skipped: certificate has no readable hex serial');

            return null;
        }

        $revoked = $this->revokedSerialsFrom($crlData);
        $ours = $this->normalizeSerial($serialHex, 16);

        if (array_key_exists($ours, $revoked)) {
            return [
                'revoked' => true,
                'method' => 'crl',
                'reason' => 'Certificate found in CRL',
                'revokedAt' => $revoked[$ours],
            ];
        }

        return [
            'revoked' => false,
            'method' => 'crl',
            'reason' => null,
            'revokedAt' => null,
        ];
    }

    /**
     * Build a serial => revocation date map from a CRL.
     *
     * Parsed with phpseclib rather than by running `openssl crl -text` and
     * reading its report. Whether a certificate may still sign is not a
     * decision to take from the formatting of a command's output: that output
     * is meant for people, and an OpenSSL upgrade or a translated locale
     * changes it without changing anything about the certificate.
     *
     * DER and PEM are both accepted; loadCRL detects which it was given, so
     * the encoding a distribution point happens to serve does not matter.
     *
     * @return array<string, string|null> Normalized serial => revocation date
     */
    private function revokedSerialsFrom(string $crlData): array
    {
        $crl = new X509;

        // loadCRL returns false for some malformed input and throws for the
        // rest — a distribution point answering with an HTML error page raises
        // SodiumException from the base64 decode. Either way this is
        // "could not read the list", not "nothing is revoked", and it must not
        // travel up as an exception from the middle of a submission.
        try {
            $parsed = $crl->loadCRL($crlData) !== false;
        } catch (\Throwable $e) {
            Log::warning('CRL could not be parsed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $parsed) {
            Log::warning('CRL could not be parsed');

            return [];
        }

        $revoked = [];

        foreach ($crl->listRevoked() ?: [] as $decimalSerial) {
            $entry = $crl->getRevoked($decimalSerial);

            $revoked[$this->normalizeSerial($decimalSerial, 10)] =
                $entry['revocationDate']['utcTime']
                ?? $entry['revocationDate']['generalTime']
                ?? null;
        }

        return $revoked;
    }

    /**
     * Reduce a serial to one comparable form: uppercase hex, no 0x, no
     * leading zeros.
     *
     * The base is a parameter rather than something to detect, because the
     * two sides disagree and the forms overlap: openssl_x509_parse() gives the
     * certificate's serial in hex, phpseclib gives the CRL's in decimal, and
     * "1000" is a valid spelling in either. Guessing reads that hex serial as
     * decimal, normalises it to 3E8, and reports a revoked certificate as
     * good — the exact failure this check exists to prevent.
     *
     * @param  int  $base  16 for a certificate serial, 10 for a CRL entry
     */
    private function normalizeSerial(string $serial, int $base): string
    {
        $serial = preg_replace('/^0x/i', '', trim($serial));

        if ($base === 10) {
            $serial = (new BigInteger($serial, 10))->toHex();
        }

        $serial = ltrim(strtoupper((string) $serial), '0');

        return $serial === '' ? '0' : $serial;
    }

    /**
     * Extract CRL distribution point URLs from certificate extensions.
     *
     * @param  array  $extensions  Certificate extensions
     * @return array List of CRL URLs
     */
    private function extractCrlUrls(array $extensions): array
    {
        $urls = [];

        // CRL Distribution Points extension
        $crlDist = $extensions['crlDistributionPoints'] ?? null;

        if ($crlDist === null) {
            return $urls;
        }

        // Parse for URIs
        if (preg_match_all('/URI:(\S+)/i', $crlDist, $matches)) {
            $urls = array_map('trim', $matches[1]);
        }

        return $urls;
    }

    /**
     * Verify certificate chain including revocation status.
     *
     * @param  string  $certificatePem  End-entity certificate
     * @param  array  $chainPems  Array of intermediate/root certificates
     * @param  bool  $checkRevocation  Whether to check revocation status
     * @return array{valid: bool, errors: array, chain: array}
     */
    public function verifyCertificateChain(string $certificatePem, array $chainPems = [], bool $checkRevocation = true): array
    {
        $errors = [];
        $chainInfo = [];

        // Verify the certificate itself
        if (! $this->isValid($certificatePem)) {
            $errors[] = 'Certificate is expired or not yet valid';
        }

        // Check revocation if requested
        if ($checkRevocation) {
            try {
                $revocationStatus = $this->checkRevocationStatus($certificatePem);
                if ($revocationStatus['revoked']) {
                    $errors[] = sprintf(
                        'Certificate is revoked (method: %s, reason: %s)',
                        $revocationStatus['method'],
                        $revocationStatus['reason'] ?? 'unknown'
                    );
                }
            } catch (\Exception $e) {
                $errors[] = 'Could not verify revocation status: '.$e->getMessage();
            }
        }

        // Parse and verify each certificate in the chain
        $allCerts = array_merge([$certificatePem], $chainPems);
        foreach ($allCerts as $index => $certPem) {
            try {
                $details = $this->parseCertificate($certPem);
                $chainInfo[] = [
                    'index' => $index,
                    'subject' => $details['subject']['CN'] ?? 'Unknown',
                    'issuer' => $details['issuer']['CN'] ?? 'Unknown',
                    'validFrom' => $details['validFrom'],
                    'validTo' => $details['validTo'],
                ];

                // Check if certificate in chain is expired
                if (! $this->isValid($certPem)) {
                    $errors[] = sprintf('Certificate at index %d is expired', $index);
                }
            } catch (CertificateException $e) {
                $errors[] = sprintf('Invalid certificate at index %d: %s', $index, $e->getMessage());
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'chain' => $chainInfo,
        ];
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
