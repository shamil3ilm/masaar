<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\DTOs\CsrData;
use App\Domains\Compliance\Zatca\Exceptions\CertificateException;

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
     * @param CsrData $data CSR configuration data
     * @return array{csr: string, privateKey: string}
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
                throw new CertificateException('Failed to generate private key: ' . openssl_error_string());
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
                throw new CertificateException('Failed to generate CSR: ' . openssl_error_string());
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

        return 'VATSA-' . str_pad($cleanVat, 15, '0', STR_PAD_LEFT);
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
        $entries[] = "dirName.3 = title=1100";

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
     * @param string $certificatePem PEM-encoded certificate
     * @return array Certificate details
     * @throws CertificateException
     */
    public function parseCertificate(string $certificatePem): array
    {
        $cert = openssl_x509_read($certificatePem);

        if ($cert === false) {
            throw new CertificateException('Invalid certificate: ' . openssl_error_string());
        }

        $details = openssl_x509_parse($cert);

        if ($details === false) {
            throw new CertificateException('Could not parse certificate');
        }

        return [
            'subject' => $details['subject'] ?? [],
            'issuer' => $details['issuer'] ?? [],
            'validFrom' => date('Y-m-d H:i:s', $details['validFrom_time_t'] ?? 0),
            'validTo' => date('Y-m-d H:i:s', $details['validTo_time_t'] ?? 0),
            'serialNumber' => $details['serialNumber'] ?? null,
            'extensions' => $details['extensions'] ?? [],
        ];
    }

    /**
     * Check if certificate is valid (not expired).
     *
     * @param string $certificatePem PEM-encoded certificate
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
     * Get certificate expiry date.
     *
     * @param string $certificatePem PEM-encoded certificate
     * @return \DateTimeImmutable|null
     */
    public function getExpiryDate(string $certificatePem): ?\DateTimeImmutable
    {
        try {
            $details = $this->parseCertificate($certificatePem);

            return new \DateTimeImmutable($details['validTo']);
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
     * @param string $certificatePem PEM-encoded certificate
     * @return string Raw signature bytes (not base64 encoded)
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
            throw new CertificateException('Failed to extract certificate signature: ' . $e->getMessage());
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
     * @param string $path File path
     * @return string PEM-encoded certificate
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
     * @param string $path File path
     * @param string|null $passphrase Optional passphrase
     * @return string PEM-encoded private key
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
            throw new CertificateException('Invalid private key: ' . openssl_error_string());
        }

        return $content;
    }
}
