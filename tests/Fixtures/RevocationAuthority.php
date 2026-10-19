<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Carbon\CarbonImmutable;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\File\X509;
use phpseclib3\Math\BigInteger;

/**
 * A certificate authority made for one test: its certificate, device
 * certificates it issues on ZATCA's curve, and revocation lists it signs.
 *
 * The committed fixtures in tests/Fixtures/Certificates have no CA key, so
 * they cannot issue a device certificate that signs, nor a list dated now -
 * and a list older than seven days is one the check must refuse.
 */
trait RevocationAuthority
{
    /** The distribution point the device certificates name. */
    private const CRL_URL = 'http://crl.authority.test/issuing.crl';

    /** @var array{certificate: string, privateKey: string}|null */
    private ?array $authority = null;

    /**
     * The CA, written as the configured chain so its lists can be verified.
     *
     * @return array{certificate: string, privateKey: string}
     */
    private function authority(): array
    {
        if ($this->authority !== null) {
            return $this->authority;
        }

        $config = $this->authorityConfig();
        $key = openssl_pkey_new($config + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => 'Test Issuing CA'], $key, $config);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 365, $config, 1), $certificate);
        openssl_pkey_export($key, $privateKey, null, $config);

        $chain = sys_get_temp_dir().'/platform-test-chain-'.getmypid().'-'.spl_object_id($this).'.pem';
        file_put_contents($chain, $certificate);
        config(['fatoora.certificate.chain_path' => $chain]);

        return $this->authority = ['certificate' => $certificate, 'privateKey' => $privateKey];
    }

    /**
     * A device certificate the CA issued, naming its distribution point.
     *
     * @return array{privateKey: string, certificate: string}
     */
    private function issuedDevice(int $serial, bool $namesList = true): array
    {
        $authority = $this->authority();
        $config = $this->authorityConfig() + ($namesList ? ['x509_extensions' => 'device'] : []);

        $key = openssl_pkey_new($config + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        $csr = openssl_csr_new(['countryName' => 'SA', 'organizationName' => 'Acme Trading', 'commonName' => 'EGS-'.$serial], $key, $config);
        openssl_x509_export(openssl_csr_sign($csr, $authority['certificate'], $authority['privateKey'], 365, $config, $serial), $certificate);
        openssl_pkey_export($key, $privateKey, null, $config);

        return ['privateKey' => $privateKey, 'certificate' => $certificate];
    }

    /**
     * A list the CA signed, revoking the serials given.
     *
     * @param  list<int>  $revoked
     */
    private function revocationList(array $revoked = [], ?CarbonImmutable $issued = null, int $days = 7): string
    {
        $authority = $this->authority();
        $issued ??= CarbonImmutable::now();

        $issuer = new X509;
        $issuer->loadX509($authority['certificate']);
        $issuer->setPrivateKey(PublicKeyLoader::load($authority['privateKey']));

        $crl = new X509;
        $crl->setStartDate($issued->toDateTimeString().' UTC');
        $crl->loadCRL($crl->saveCRL($crl->signCRL($issuer, new X509)));

        foreach ($revoked as $serial) {
            $crl->revoke(new BigInteger((string) $serial, 10), $issued->format('D, d M Y H:i:s O'));
        }

        $crl->setStartDate($issued->toDateTimeString().' UTC');
        $crl->setEndDate($issued->addDays($days)->toDateTimeString().' UTC');

        return (string) $crl->saveCRL($crl->signCRL($issuer, $crl), X509::FORMAT_DER);
    }

    /**
     * @return array<string, mixed>
     */
    private function authorityConfig(): array
    {
        $path = sys_get_temp_dir().'/platform-test-authority.cnf';

        if (! file_exists($path)) {
            file_put_contents($path, <<<'CNF'
                [req]
                default_bits = 2048
                default_md = sha256
                distinguished_name = dn
                prompt = no

                [dn]

                [device]
                crlDistributionPoints = URI:http://crl.authority.test/issuing.crl
                CNF);
        }

        return ['config' => $path, 'digest_alg' => 'sha256'];
    }
}
