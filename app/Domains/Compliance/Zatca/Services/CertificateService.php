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
    /**
     * Generate Certificate Signing Request (CSR).
     *
     * @param CsrData $data CSR configuration data
     * @return array{csr: string, privateKey: string}
     * @throws CertificateException
     */
    public function generateCsr(CsrData $data): array
    {
        // Generate ECDSA key pair
        $keyConfig = [
            'curve_name' => 'secp256k1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        $privateKey = openssl_pkey_new($keyConfig);

        if ($privateKey === false) {
            throw new CertificateException('Failed to generate private key: ' . openssl_error_string());
        }

        // Build distinguished name
        $dn = [
            'C' => 'SA',                                    // Country (Saudi Arabia)
            'O' => $data->organizationName,                 // Organization name
            'OU' => $data->organizationUnit,                // Branch/unit
            'CN' => $data->commonName,                      // EGS serial number
        ];

        // CSR configuration
        $csrConfig = [
            'digest_alg' => 'sha256',
            'req_extensions' => 'v3_req',
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

        // Add ZATCA-specific extensions to CSR
        $csrWithExtensions = $this->addZatcaExtensions($csrPem, $data);

        return [
            'csr' => $csrWithExtensions,
            'privateKey' => $privateKeyPem,
        ];
    }

    /**
     * Add ZATCA-specific extensions to CSR.
     */
    private function addZatcaExtensions(string $csrPem, CsrData $data): string
    {
        // ZATCA requires specific OID extensions in the CSR:
        // - 2.5.4.97 = Organization Identifier (VAT number with prefix)
        // - Various custom attributes for invoice types

        // For now, return the basic CSR
        // Full implementation would use ASN.1 encoding to add extensions
        return $csrPem;
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
     * @param string $certificatePem PEM-encoded certificate
     * @return string Base64-encoded certificate signature
     */
    public function getCertificateSignature(string $certificatePem): string
    {
        // Convert PEM to DER
        $lines = explode("\n", $certificatePem);
        $der = '';

        foreach ($lines as $line) {
            if (strpos($line, '-----') === false) {
                $der .= $line;
            }
        }

        $derBytes = base64_decode($der);

        // The signature is at the end of the certificate DER structure
        // This is a simplified extraction - full ASN.1 parsing would be more accurate
        return base64_encode(substr($derBytes, -256));
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
