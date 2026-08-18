<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Exceptions\SigningException;

/**
 * ECDSA digital signature service.
 *
 * Implements ECDSA signing using secp256k1 curve as required by ZATCA.
 * Used for signing invoices and generating QR code tags 7, 8, 9.
 *
 * Cryptographic settings are config-driven for flexibility.
 * See config/zatca.php 'crypto' section.
 */
class EcdsaSigner
{
    /**
     * Get the hash algorithm for signing.
     * Default: OPENSSL_ALGO_SHA256 per ZATCA specification.
     */
    private function getHashAlgorithm(): int
    {
        return config('fatoora.crypto.hash_algorithm', OPENSSL_ALGO_SHA256);
    }

    /**
     * Get the EC curve name.
     * Default: secp256k1 per ZATCA specification.
     */
    private function getCurveName(): string
    {
        return config('fatoora.crypto.curve', 'secp256k1');
    }

    /**
     * Get the coordinate length in bytes.
     * Default: 32 bytes for secp256k1.
     */
    private function getCoordinateLength(): int
    {
        return (int) config('fatoora.crypto.coordinate_length', 32);
    }

    /**
     * Sign data with private key.
     *
     * @param  string  $data  Data to sign
     * @param  string  $privateKeyPem  PEM-encoded private key
     * @return string Base64-encoded signature
     *
     * @throws SigningException
     */
    public function sign(string $data, string $privateKeyPem): string
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            throw new SigningException('Invalid private key: '.openssl_error_string());
        }

        $signature = '';
        $success = openssl_sign($data, $signature, $privateKey, $this->getHashAlgorithm());

        if (! $success) {
            throw new SigningException('Signing failed: '.openssl_error_string());
        }

        return base64_encode($signature);
    }

    /**
     * Verify signature.
     *
     * @param  string  $data  Original data
     * @param  string  $signatureBase64  Base64-encoded signature
     * @param  string  $publicKeyPem  PEM-encoded public key
     * @return bool True if valid
     */
    public function verify(string $data, string $signatureBase64, string $publicKeyPem): bool
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            return false;
        }

        $signature = base64_decode($signatureBase64);

        return openssl_verify($data, $signature, $publicKey, $this->getHashAlgorithm()) === 1;
    }

    /**
     * Generate new ECDSA key pair.
     *
     * @return array{privateKey: string, publicKey: string}
     *
     * @throws SigningException
     */
    public function generateKeyPair(): array
    {
        $config = [
            'curve_name' => $this->getCurveName(),
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        $keyPair = openssl_pkey_new($config);

        if ($keyPair === false) {
            throw new SigningException('Key generation failed: '.openssl_error_string());
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
     * @param  string  $certificatePem  PEM-encoded certificate
     * @return string PEM-encoded public key
     *
     * @throws SigningException
     */
    public function extractPublicKey(string $certificatePem): string
    {
        $cert = openssl_x509_read($certificatePem);

        if ($cert === false) {
            throw new SigningException('Invalid certificate: '.openssl_error_string());
        }

        $publicKey = openssl_pkey_get_public($cert);

        if ($publicKey === false) {
            throw new SigningException('Could not extract public key: '.openssl_error_string());
        }

        $details = openssl_pkey_get_details($publicKey);

        return $details['key'];
    }

    /**
     * Get raw public key bytes for QR code tag 8.
     *
     * ZATCA requires the uncompressed EC point format:
     * - 0x04 prefix (uncompressed point indicator)
     * - X coordinate (coordinate_length bytes per config)
     * - Y coordinate (coordinate_length bytes per config)
     *
     * Total: 1 + (2 * coordinate_length) bytes, base64-encoded for QR code.
     * Default: 65 bytes for secp256k1 (32-byte coordinates).
     *
     * @param  string  $publicKeyPem  PEM-encoded public key or certificate
     * @return string Base64-encoded raw public key
     *
     * @throws SigningException If key is not an EC key or extraction fails
     */
    public function getPublicKeyBytes(string $publicKeyPem): string
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            throw new SigningException('Invalid public key: '.openssl_error_string());
        }

        $details = openssl_pkey_get_details($publicKey);

        // Verify this is an EC key
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            $curve = $this->getCurveName();
            throw new SigningException("ZATCA requires ECDSA key ({$curve}), got non-EC key type");
        }

        // Extract the raw EC point (uncompressed format per ZATCA spec)
        if (! isset($details['ec']['x']) || ! isset($details['ec']['y'])) {
            throw new SigningException('Could not extract EC point coordinates from key');
        }

        // Pad coordinates to configured length (32 bytes for secp256k1)
        $coordLength = $this->getCoordinateLength();
        $x = str_pad($details['ec']['x'], $coordLength, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], $coordLength, "\x00", STR_PAD_LEFT);

        // Build uncompressed EC point: 0x04 + X + Y
        $rawKey = chr(0x04).$x.$y;

        return base64_encode($rawKey);
    }

    /**
     * Validate that a public key is suitable for ZATCA signing.
     *
     * @param  string  $publicKeyPem  PEM-encoded public key
     * @return array{valid: bool, curve: string|null, error: string|null}
     */
    public function validatePublicKey(string $publicKeyPem): array
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            return [
                'valid' => false,
                'curve' => null,
                'error' => 'Invalid public key format',
            ];
        }

        $details = openssl_pkey_get_details($publicKey);

        $requiredCurve = $this->getCurveName();

        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            return [
                'valid' => false,
                'curve' => null,
                'error' => "Key is not an EC key (ZATCA requires {$requiredCurve})",
            ];
        }

        $curve = $details['ec']['curve_name'] ?? 'unknown';

        if ($curve !== $requiredCurve) {
            return [
                'valid' => false,
                'curve' => $curve,
                'error' => "Wrong curve: {$curve} (ZATCA requires {$requiredCurve})",
            ];
        }

        return [
            'valid' => true,
            'curve' => $curve,
            'error' => null,
        ];
    }
}
