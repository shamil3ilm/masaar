<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\Exceptions\SigningException;

/**
 * ECDSA digital signature service.
 *
 * Implements ECDSA signing using secp256k1 curve as required by ZATCA.
 * Used for signing invoices and generating QR code tags 7, 8, 9.
 *
 * The curve, its coordinate size and the hash are constants on FatooraConfig
 * rather than configuration, because the authority mandates all three and a
 * deployment that changes one produces signatures nothing can verify.
 */
class EcdsaSigner
{
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
        $success = openssl_sign($data, $signature, $privateKey, FatooraConfig::HASH_ALGORITHM);

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

        return openssl_verify($data, $signature, $publicKey, FatooraConfig::HASH_ALGORITHM) === 1;
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
     * - X coordinate, left-padded to the curve's field size
     * - Y coordinate, left-padded to the curve's field size
     *
     * 65 bytes for secp256k1, base64-encoded for the QR code.
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
            throw new SigningException('ZATCA requires an ECDSA key on '.FatooraConfig::EC_CURVE.', got a non-EC key');
        }

        // SubjectPublicKeyInfo, not the bare EC point.
        //
        // This built 0x04 + X + Y and called it "uncompressed format per
        // ZATCA spec". QR tag 8 holds the DER structure instead - 88 bytes
        // against the point's 65 - and the authority answers "ECDSA Public
        // Key does not match with qr code ECDSA public key" for the shorter
        // one. Checked against the QR ZATCA's own SDK produces.
        $pem = $details['key'] ?? '';

        if ($pem === '') {
            throw new SigningException('Certificate carries no public key.');
        }

        $der = base64_decode(str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', '', '
'],
            '',
            $pem
        ), true);

        if ($der === false) {
            throw new SigningException('Public key is not valid base64.');
        }

        return base64_encode($der);
    }
}
