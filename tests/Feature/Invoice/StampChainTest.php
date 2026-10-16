<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Compliance\Fatoora\Exceptions\SigningException;
use App\Domains\Compliance\Fatoora\Services\CertificateChain;
use App\Domains\Invoice\Services\CadesSignature;
use OpenSSLAsymmetricKey;
use phpseclib3\File\ASN1;
use Tests\TestCase;

/**
 * The chain a PDF stamp carries up to the trust anchor (Security Features
 * standard, 2.2.1 item 17; SignedData.certificates in the PAdES structure).
 *
 * Proved the way a verifier would: OpenSSL is given the root alone and must
 * build the path to the device certificate from what the signature carries.
 */
class StampChainTest extends TestCase
{
    /** @var array<string, array{key: OpenSSLAsymmetricKey, pem: string}> */
    private array $pki = [];

    private string $bundle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pki['root'] = $this->issue('Test Root CA', null, 'v3_ca');
        $this->pki['issuing'] = $this->issue('Test Issuing CA', 'root', 'v3_ca');
        $this->pki['device'] = $this->issue('EGS-TEST-0001', 'issuing', 'v3_leaf');
        $this->pki['stranger'] = $this->issue('Other Root CA', null, 'v3_ca');

        // Out of order and with a stranger in it, as a hand-made bundle is.
        $this->bundle = (string) tempnam(sys_get_temp_dir(), 'chain');
        file_put_contents($this->bundle, $this->pki['stranger']['pem'].$this->pki['root']['pem'].$this->pki['issuing']['pem']);
        config(['fatoora.certificate.chain_path' => $this->bundle]);
    }

    protected function tearDown(): void
    {
        @unlink($this->bundle);

        parent::tearDown();
    }

    public function test_the_chain_reaches_the_anchor(): void
    {
        $this->assertSame(
            [trim($this->pki['issuing']['pem']), trim($this->pki['root']['pem'])],
            app(CertificateChain::class)->above($this->pki['device']['pem']),
        );
    }

    public function test_openssl_builds_the_path(): void
    {
        $content = 'the bytes a ByteRange names';
        $cms = app(CadesSignature::class)->sign(
            $content,
            $this->privateKey('device'),
            $this->pki['device']['pem'],
            app(CertificateChain::class)->above($this->pki['device']['pem']),
        );

        $certificates = ASN1::decodeBER($cms)[0]['content'][1]['content'][0]['content'][3]['content'];
        $this->assertCount(3, $certificates);

        $this->assertTrue($this->verifiesAgainstRoot($content, $cms), (string) openssl_error_string());
    }

    public function test_another_environments_bundle_adds_nothing(): void
    {
        file_put_contents($this->bundle, $this->pki['stranger']['pem']);

        $this->assertSame([], app(CertificateChain::class)->above($this->pki['device']['pem']));
    }

    public function test_an_unreadable_bundle_is_refused(): void
    {
        config(['fatoora.certificate.chain_path' => $this->bundle.'-missing']);

        $this->expectException(SigningException::class);

        app(CertificateChain::class)->above($this->pki['device']['pem']);
    }

    public function test_unset_means_device_only(): void
    {
        config(['fatoora.certificate.chain_path' => null]);

        $this->assertSame([], app(CertificateChain::class)->above($this->pki['device']['pem']));
    }

    /**
     * @return array{key: OpenSSLAsymmetricKey, pem: string}
     */
    private function issue(string $commonName, ?string $issuer, string $extensions): array
    {
        $config = ['config' => $this->opensslConfig(), 'digest_alg' => 'sha256', 'x509_extensions' => $extensions];
        $key = openssl_pkey_new($config + ['curve_name' => 'secp256k1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertNotFalse($key, (string) openssl_error_string());

        $csr = openssl_csr_new(['countryName' => 'SA', 'commonName' => $commonName], $key, $config);
        $this->assertNotFalse($csr, (string) openssl_error_string());

        $certificate = $issuer === null
            ? openssl_csr_sign($csr, null, $key, 30, $config, random_int(1, PHP_INT_MAX))
            : openssl_csr_sign($csr, $this->pki[$issuer]['pem'], $this->pki[$issuer]['key'], 30, $config, random_int(1, PHP_INT_MAX));
        $this->assertNotFalse($certificate, (string) openssl_error_string());

        openssl_x509_export($certificate, $pem);

        return ['key' => $key, 'pem' => $pem];
    }

    private function privateKey(string $name): string
    {
        openssl_pkey_export($this->pki[$name]['key'], $pem, null, ['config' => $this->opensslConfig()]);

        return $pem;
    }

    private function verifiesAgainstRoot(string $content, string $cms): bool
    {
        $dir = sys_get_temp_dir();
        [$contentFile, $cmsFile, $rootFile] = [tempnam($dir, 'pdf'), tempnam($dir, 'cms'), tempnam($dir, 'ca')];

        try {
            file_put_contents($contentFile, $content);
            file_put_contents($cmsFile, $cms);
            // The root only: the issuing CA has to come from the signature.
            file_put_contents($rootFile, $this->pki['root']['pem']);

            return openssl_cms_verify(
                $contentFile,
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
                ca_info: [$rootFile],
                sigfile: $cmsFile,
                encoding: OPENSSL_ENCODING_DER,
            );
        } finally {
            array_map(unlink(...), [$contentFile, $cmsFile, $rootFile]);
        }
    }

    private function opensslConfig(): string
    {
        $path = sys_get_temp_dir().'/masaar-test-chain-openssl.cnf';

        if (! file_exists($path)) {
            file_put_contents($path, <<<'CNF'
                [req]
                default_bits = 2048
                default_md = sha256
                distinguished_name = dn
                prompt = no

                [dn]

                [v3_ca]
                basicConstraints = critical, CA:TRUE
                keyUsage = critical, keyCertSign, cRLSign
                subjectKeyIdentifier = hash

                [v3_leaf]
                basicConstraints = critical, CA:FALSE
                keyUsage = critical, digitalSignature
                subjectKeyIdentifier = hash
                authorityKeyIdentifier = keyid
                CNF);
        }

        return $path;
    }
}
