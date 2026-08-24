<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\Services\EcdsaSigner;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The curve and hash every signature and QR tag 8 is built on.
 *
 * EcdsaSigner took its curve, its coordinate size and its hash from
 * config/fatoora.php, two of them reachable from the environment, while key
 * generation was pinned in code. Nothing reconciled the two, so a deployment
 * could sign on a curve its keys were never generated for — and the coordinate
 * length, which is a property of the curve rather than a choice, could be set
 * to disagree with the curve it describes.
 *
 * None of that failed locally. It failed at the authority, or produced a tag 8
 * decoding to the wrong number of bytes, which ZATCA reads by offset.
 *
 * The class had no tests at all before this, despite producing every signature
 * the platform emits.
 */
class SigningKeyTest extends TestCase
{
    use SigningCredentials;

    private EcdsaSigner $signer;

    private array $credentials;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = app(EcdsaSigner::class);
        $this->credentials = $this->selfSignedCredentials();
    }

    /**
     * Tag 8 is an uncompressed EC point: a 0x04 prefix and two coordinates,
     * each left-padded to the curve's field size. ZATCA reads it by offset, so
     * the length is part of the format rather than an artefact of it.
     */
    public function test_tag_eight_is_an_uncompressed_point(): void
    {
        $raw = base64_decode($this->signer->getPublicKeyBytes($this->credentials['certificate']));

        $this->assertSame(1 + 2 * FatooraConfig::EC_COORDINATE_BYTES, strlen($raw));
        $this->assertSame(65, strlen($raw));
        $this->assertSame(0x04, ord($raw[0]));
    }

    /**
     * The regression this file exists for.
     *
     * The coordinate size describes the curve; it is not a choice. Setting the
     * key that used to carry it proves the signer no longer consults it.
     */
    public function test_config_cannot_resize_tag_eight(): void
    {
        config(['fatoora.crypto.coordinate_length' => 48]);
        config(['fatoora.crypto.curve' => 'prime256v1']);

        $raw = base64_decode($this->signer->getPublicKeyBytes($this->credentials['certificate']));

        $this->assertSame(65, strlen($raw), 'Configuration changed the size of QR tag 8.');
    }

    public function test_a_signature_verifies(): void
    {
        $data = 'the invoice hash that would be signed';

        $this->assertTrue($this->signer->verify(
            $data,
            $this->signer->sign($data, $this->credentials['privateKey']),
            $this->credentials['certificate']
        ));
    }

    /**
     * Checked against OpenSSL directly rather than through verify().
     *
     * Both sides used to read the hash from the same config key, so a wrong
     * value round-tripped happily and agreed with itself. Only an outside
     * verifier fixed on SHA-256 — which is what ZATCA is — can tell.
     */
    public function test_config_cannot_change_the_hash(): void
    {
        config(['fatoora.crypto.hash_algorithm' => OPENSSL_ALGO_SHA512]);

        $data = 'the invoice hash that would be signed';
        $signature = base64_decode($this->signer->sign($data, $this->credentials['privateKey']));

        $this->assertSame(
            1,
            openssl_verify(
                $data,
                $signature,
                openssl_pkey_get_public($this->credentials['certificate']),
                OPENSSL_ALGO_SHA256
            ),
            'Configuration moved the signing hash away from SHA-256.'
        );
    }
}
