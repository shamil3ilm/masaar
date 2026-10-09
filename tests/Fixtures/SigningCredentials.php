<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * A secp256k1 keypair and self-signed certificate, generated per test run.
 *
 * ZATCA's own credentials cannot be committed and cannot be obtained without a
 * CSID from the portal, but nothing on the signing path needs a ZATCA-issued
 * certificate specifically — it needs a certificate on the curve ZATCA
 * mandates. Generating one here keeps the tests independent of any fixture
 * whose expiry would eventually break the suite for reasons unrelated to the
 * code.
 *
 * Shared because a standard (B2B) invoice cannot be built without credentials
 * at all: the Phase 2 QR requires the signature, public key and certificate
 * signature, and QrCodeGenerator refuses to assemble one without them.
 */
trait SigningCredentials
{
    /**
     * @param  int  $serial  OpenSSL prints a serial of 32 bits or more as hex,
     *                       as it does for every ZATCA-issued certificate.
     * @return array{privateKey: string, certificate: string}
     */
    /**
     * ZATCA's own test credentials, shipped inside the SDK.
     *
     * The certificate is issued by the authority's pre-production CA -
     * PRZEINVOICESCA4-CA - rather than by this test run, and for the same
     * taxpayer the conformance documents name. Signing with it is what lets
     * the SDK check the certificate, the QR that embeds it, the signature
     * over both and the PIH chain: four checks a key signed by nobody can
     * only ever be excluded from.
     *
     * The key ships as bare base64 DER, the shape the SDK writes rather than
     * one OpenSSL reads, so it is wrapped before use.
     *
     * @return array{privateKey: string, certificate: string}|null
     */
    private function authorityCredentials(?string $sdk): ?array
    {
        if ($sdk === null) {
            return null;
        }

        $certPath = $sdk.'/Data/Certificates/cert.pem';
        $keyPath = $sdk.'/Data/Certificates/ec-secp256k1-priv-key.pem';

        if (! is_file($certPath) || ! is_file($keyPath)) {
            return null;
        }

        $key = trim((string) file_get_contents($keyPath));

        if (! str_contains($key, 'BEGIN')) {
            $key = '-----BEGIN EC PRIVATE KEY-----'.PHP_EOL
                .chunk_split($key, 64, PHP_EOL)
                .'-----END EC PRIVATE KEY-----'.PHP_EOL;
        }

        // The certificate ships the same way: base64 DER, where the rest of
        // the platform passes PEM around.
        $certificate = trim((string) file_get_contents($certPath));

        if (! str_contains($certificate, 'BEGIN CERTIFICATE')) {
            $certificate = '-----BEGIN CERTIFICATE-----'.PHP_EOL
                .chunk_split($certificate, 64, PHP_EOL)
                .'-----END CERTIFICATE-----'.PHP_EOL;
        }

        return [
            'privateKey' => $key,
            'certificate' => $certificate,
        ];
    }

    private function selfSignedCredentials(int $serial = 0): array
    {
        // An explicit config path is required: without one, OpenSSL looks for
        // openssl.cnf at a build-time location that does not exist on Windows,
        // and every key operation fails with a BIO error. CertificateService
        // writes its own config for the same reason.
        $config = ['config' => $this->opensslConfig(), 'digest_alg' => 'sha256'];

        $key = openssl_pkey_new($config + [
            'curve_name' => 'secp256k1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        $this->assertNotFalse($key, 'could not generate a secp256k1 key: '.openssl_error_string());

        $csr = openssl_csr_new([
            'countryName' => 'SA',
            'organizationName' => 'Acme Trading',
            'organizationalUnitName' => 'Riyadh',
            'commonName' => 'EGS-TEST-0001',
        ], $key, $config);

        $this->assertNotFalse($csr, 'could not build a CSR: '.openssl_error_string());

        $cert = openssl_csr_sign($csr, null, $key, 365, $config, $serial);
        $this->assertNotFalse($cert, 'could not self-sign: '.openssl_error_string());

        openssl_x509_export($cert, $certificatePem);
        openssl_pkey_export($key, $privateKeyPem, null, $config);

        return ['privateKey' => $privateKeyPem, 'certificate' => $certificatePem];
    }

    private function opensslConfig(): string
    {
        $path = sys_get_temp_dir().'/masaar-test-openssl.cnf';

        if (! file_exists($path)) {
            // default_bits is read even for EC keys; without it PHP sees 0 and
            // refuses the key as too short.
            file_put_contents($path, <<<'CNF'
                [req]
                default_bits = 2048
                default_md = sha256
                distinguished_name = dn
                prompt = no

                [dn]
                CNF);
        }

        return $path;
    }
}
