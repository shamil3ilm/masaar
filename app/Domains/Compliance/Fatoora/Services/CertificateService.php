<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use App\Domains\Compliance\Fatoora\Exceptions\CertificateException;
use App\Domains\Compliance\Fatoora\Helpers\FatooraTime;
use App\Support\SafeUrl;

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

            // Build distinguished name with ZATCA requirements
            $dn = [
                'C' => 'SA',
                'O' => $data->organizationName,
                'OU' => $data->organizationUnit,
                'CN' => $data->commonName,
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

            // Export CSR
            $csrPem = '';
            openssl_csr_export($csr, $csrPem);

            // Export private key
            $privateKeyPem = '';
            openssl_pkey_export($privateKey, $privateKeyPem);

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
        $sanEntries = $this->buildSubjectAltName($data);

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
{$sanEntries}
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
     * Build Subject Alternative Name entries for ZATCA.
     */
    private function buildSubjectAltName(CsrData $data): string
    {
        $entries = [];

        // Serial number (EGS serial)
        $entries[] = "dirName.1 = SN={$data->serialNumber}";

        // UID - Unique identifier (VAT number)
        $entries[] = "dirName.2 = UID={$data->vatNumber}";

        // Title - Invoice type (1100 for all types)
        $entries[] = 'dirName.3 = title=1100';

        // Registered address
        if (! empty($data->location)) {
            $entries[] = "dirName.4 = registeredAddress={$data->location}";
        }

        // Business category
        if (! empty($data->industry)) {
            $entries[] = "dirName.5 = businessCategory={$data->industry}";
        }

        return implode("\n", $entries);
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
     * Check certificate revocation status via OCSP and/or CRL.
     *
     * This method checks if a certificate has been revoked by the issuer.
     * It attempts OCSP first (faster, real-time), then falls back to CRL.
     *
     * @param  string  $certificatePem  PEM-encoded certificate to check
     * @param  string|null  $issuerCertPem  PEM-encoded issuer certificate (optional, extracted if not provided)
     * @return array{revoked: bool, method: string, reason: string|null, revokedAt: string|null}
     *
     * @throws CertificateException
     */
    public function checkRevocationStatus(string $certificatePem, ?string $issuerCertPem = null): array
    {
        $details = $this->parseCertificate($certificatePem);
        $extensions = $details['extensions'] ?? [];

        // Try OCSP first (preferred - real-time status)
        $ocspUrl = $this->extractOcspUrl($extensions);
        if ($ocspUrl !== null && $this->mayFetch($ocspUrl, 'OCSP')) {
            try {
                $ocspResult = $this->checkOcsp($certificatePem, $issuerCertPem, $ocspUrl);
                if ($ocspResult !== null) {
                    return $ocspResult;
                }
            } catch (\Exception $e) {
                // OCSP failed, fall back to CRL
                \Log::warning('OCSP check failed, falling back to CRL', [
                    'error' => $e->getMessage(),
                    'ocsp_url' => $ocspUrl,
                ]);
            }
        }

        // Fall back to CRL
        $crlUrls = $this->extractCrlUrls($extensions);
        foreach ($crlUrls as $crlUrl) {
            if (! $this->mayFetch($crlUrl, 'CRL')) {
                continue;
            }

            try {
                $crlResult = $this->checkCrl($certificatePem, $crlUrl);
                if ($crlResult !== null) {
                    return $crlResult;
                }
            } catch (\Exception $e) {
                \Log::warning('CRL check failed', [
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
            'warning' => 'No OCSP or CRL endpoints available for revocation checking',
        ];
    }

    /**
     * Check certificate revocation via OCSP (Online Certificate Status Protocol).
     *
     * @param  string  $certificatePem  Certificate to check
     * @param  string|null  $issuerCertPem  Issuer certificate
     * @param  string  $ocspUrl  OCSP responder URL
     * @return array|null Revocation status or null if check failed
     */
    private function checkOcsp(string $certificatePem, ?string $issuerCertPem, string $ocspUrl): ?array
    {
        // Create temporary files for OpenSSL
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $issuerFile = $issuerCertPem ? tempnam(sys_get_temp_dir(), 'issuer_') : null;

        try {
            file_put_contents($certFile, $certificatePem);

            if ($issuerCertPem && $issuerFile) {
                file_put_contents($issuerFile, $issuerCertPem);
            }

            // Build OCSP request using OpenSSL
            $issuerArg = $issuerFile ? "-issuer {$issuerFile}" : '';

            // Generate OCSP request
            $requestCmd = sprintf(
                'openssl ocsp -cert %s %s -url %s -text 2>&1',
                escapeshellarg($certFile),
                $issuerArg,
                escapeshellarg($ocspUrl)
            );

            $output = shell_exec($requestCmd);

            if ($output === null) {
                return null;
            }

            // Parse OCSP response
            if (stripos($output, 'revoked') !== false) {
                $revokedAt = null;
                $reason = null;

                if (preg_match('/Revocation Time:\s*(.+)/i', $output, $matches)) {
                    $revokedAt = trim($matches[1]);
                }
                if (preg_match('/Reason:\s*(.+)/i', $output, $matches)) {
                    $reason = trim($matches[1]);
                }

                return [
                    'revoked' => true,
                    'method' => 'ocsp',
                    'reason' => $reason,
                    'revokedAt' => $revokedAt,
                ];
            }

            if (stripos($output, 'good') !== false) {
                return [
                    'revoked' => false,
                    'method' => 'ocsp',
                    'reason' => null,
                    'revokedAt' => null,
                ];
            }

            return null;
        } finally {
            // Clean up temporary files
            if (file_exists($certFile)) {
                unlink($certFile);
            }
            if ($issuerFile && file_exists($issuerFile)) {
                unlink($issuerFile);
            }
        }
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
        // Download CRL
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Masaar-ZATCA-Client/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $crlData = @file_get_contents($crlUrl, false, $context);

        if ($crlData === false) {
            return null;
        }

        // Get certificate serial number. The hex form is required: X.509
        // serials run to 20 octets and will not fit in a PHP int.
        $details = $this->parseCertificate($certificatePem);
        $serialHex = $details['serialNumberHex'] ?? null;

        if ($serialHex === null) {
            // Inconclusive, not "not revoked" — the caller must not read a
            // missing serial as a clean bill of health.
            \Log::warning('CRL check skipped: certificate has no readable hex serial');

            return null;
        }

        // Create temporary files for OpenSSL CRL verification
        $crlFile = tempnam(sys_get_temp_dir(), 'crl_');

        try {
            file_put_contents($crlFile, $crlData);

            // Parse CRL using OpenSSL
            $crlCmd = sprintf(
                'openssl crl -in %s -inform DER -text 2>&1',
                escapeshellarg($crlFile)
            );

            $output = shell_exec($crlCmd);

            if ($output === null) {
                // Try PEM format if DER failed
                $crlCmd = sprintf(
                    'openssl crl -in %s -inform PEM -text 2>&1',
                    escapeshellarg($crlFile)
                );
                $output = shell_exec($crlCmd);
            }

            if ($output === null) {
                return null;
            }

            $revoked = $this->revokedSerialsFrom($output);
            $ours = $this->normalizeSerial($serialHex);

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
        } finally {
            if (file_exists($crlFile)) {
                unlink($crlFile);
            }
        }
    }

    /**
     * Whether a revocation endpoint taken from a certificate may be contacted.
     *
     * The address is chosen by whoever supplied the certificate, so it is
     * treated as untrusted input rather than configuration. A refusal is
     * logged: an operator needs to tell "the responder is down" apart from
     * "we declined to call that host".
     */
    private function mayFetch(string $url, string $kind): bool
    {
        $reason = SafeUrl::reject($url, 'security.revocation_hosts');

        if ($reason === null) {
            return true;
        }

        \Log::warning("{$kind} endpoint refused", ['url' => $url, 'reason' => $reason]);

        return false;
    }

    /**
     * Build a serial => revocation date map from `openssl crl -text` output.
     *
     * Parsed into an exact-match set rather than scanned with a regex per
     * serial. A substring search over this output matches prefixes, so
     * "Serial Number: 4F8A" would report a hit against an unrelated entry
     * "4F8A2B1C..." — reporting a valid certificate as revoked.
     *
     * @return array<string, string|null> Normalized serial => revocation date
     */
    private function revokedSerialsFrom(string $opensslOutput): array
    {
        // Horizontal whitespace only between the serial and the line break:
        // \s* would swallow the newline the Revocation Date group needs.
        $matched = preg_match_all(
            '/Serial Number:[ \t]*([0-9A-Fa-f]+)[ \t]*(?:\R[ \t]*Revocation Date:[ \t]*(.+))?/',
            $opensslOutput,
            $entries,
            PREG_SET_ORDER
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        $revoked = [];

        foreach ($entries as $entry) {
            $revoked[$this->normalizeSerial($entry[1])] = isset($entry[2]) ? trim($entry[2]) : null;
        }

        return $revoked;
    }

    /**
     * Reduce a serial to one comparable form.
     *
     * openssl_x509_parse() and `openssl crl -text` differ on the 0x prefix,
     * on case, and on leading zero padding, so both sides are normalised
     * before comparison.
     */
    private function normalizeSerial(string $serial): string
    {
        $serial = preg_replace('/^0x/i', '', trim($serial));
        $serial = ltrim(strtoupper($serial), '0');

        return $serial === '' ? '0' : $serial;
    }

    /**
     * Extract OCSP responder URL from certificate extensions.
     *
     * @param  array  $extensions  Certificate extensions
     * @return string|null OCSP URL or null if not found
     */
    private function extractOcspUrl(array $extensions): ?string
    {
        // Authority Information Access (AIA) extension contains OCSP URL
        $aia = $extensions['authorityInfoAccess'] ?? null;

        if ($aia === null) {
            return null;
        }

        // Parse AIA for OCSP URI
        if (preg_match('/OCSP\s*-\s*URI:(\S+)/i', $aia, $matches)) {
            return trim($matches[1]);
        }

        return null;
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
            $issuerPem = $chainPems[0] ?? null;
            try {
                $revocationStatus = $this->checkRevocationStatus($certificatePem, $issuerPem);
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
                \Log::warning('Certificate revocation check failed', ['error' => $e->getMessage()]);
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
