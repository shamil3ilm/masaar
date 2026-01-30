<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\Exceptions\SigningException;

/**
 * ECDSA digital signature service.
 *
 * Implements ECDSA signing using secp256k1 curve as required by ZATCA.
 * Used for signing invoices and generating QR code tags 7, 8, 9.
 */
class EcdsaSigner
{
    private const CURVE = OPENSSL_ALGO_SHA256;

    /**
     * Sign data with private key.
     *
     * @param string $data Data to sign
     * @param string $privateKeyPem PEM-encoded private key
     * @return string Base64-encoded signature
     * @throws SigningException
     */
    public function sign(string $data, string $privateKeyPem): string
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            throw new SigningException('Invalid private key: ' . openssl_error_string());
        }

        $signature = '';
        $success = openssl_sign($data, $signature, $privateKey, self::CURVE);

        if (! $success) {
            throw new SigningException('Signing failed: ' . openssl_error_string());
        }

        return base64_encode($signature);
    }

    /**
     * Verify signature.
     *
     * @param string $data Original data
     * @param string $signatureBase64 Base64-encoded signature
     * @param string $publicKeyPem PEM-encoded public key
     * @return bool True if valid
     */
    public function verify(string $data, string $signatureBase64, string $publicKeyPem): bool
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            return false;
        }

        $signature = base64_decode($signatureBase64);

        return openssl_verify($data, $signature, $publicKey, self::CURVE) === 1;
    }

    /**
     * Generate new ECDSA key pair.
     *
     * @return array{privateKey: string, publicKey: string}
     * @throws SigningException
     */
    public function generateKeyPair(): array
    {
        $config = [
            'curve_name' => 'secp256k1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        $keyPair = openssl_pkey_new($config);

        if ($keyPair === false) {
            throw new SigningException('Key generation failed: ' . openssl_error_string());
        }

        // Export private key
        $privateKeyPem = '';
        openssl_pkey_export($keyPair, $privateKeyPem);

        // Export public key
        $keyDetails = openssl_pkey_get_details($keyPair);
        $publicKeyPem = $keyDetails['key'];

        return [
            'privateKey' => $privateKeyPem,
            'publicKey' => $publicKeyPem,
        ];
    }

    /**
     * Extract public key from certificate.
     *
     * @param string $certificatePem PEM-encoded certificate
     * @return string PEM-encoded public key
     * @throws SigningException
     */
    public function extractPublicKey(string $certificatePem): string
    {
        $cert = openssl_x509_read($certificatePem);

        if ($cert === false) {
            throw new SigningException('Invalid certificate: ' . openssl_error_string());
        }

        $publicKey = openssl_pkey_get_public($cert);

        if ($publicKey === false) {
            throw new SigningException('Could not extract public key: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($publicKey);

        return $details['key'];
    }

    /**
     * Get raw public key bytes (for QR code tag 8).
     *
     * @param string $publicKeyPem PEM-encoded public key
     * @return string Base64-encoded raw public key
     */
    public function getPublicKeyBytes(string $publicKeyPem): string
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        $details = openssl_pkey_get_details($publicKey);

        // Extract the raw EC point (uncompressed)
        if (isset($details['ec']['x']) && isset($details['ec']['y'])) {
            $rawKey = chr(0x04) . $details['ec']['x'] . $details['ec']['y'];
            return base64_encode($rawKey);
        }

        // Fallback: return DER-encoded public key
        $pem = $details['key'];
        $der = $this->pemToDer($pem);

        return base64_encode($der);
    }

    /**
     * Convert PEM to DER format.
     */
    private function pemToDer(string $pem): string
    {
        $lines = explode("\n", $pem);
        $der = '';

        foreach ($lines as $line) {
            if (strpos($line, '-----') === false) {
                $der .= $line;
            }
        }

        return base64_decode($der);
    }
}
