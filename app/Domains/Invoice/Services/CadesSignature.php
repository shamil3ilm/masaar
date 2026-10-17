<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\SigningException;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\EcdsaSigner;
use phpseclib3\File\ASN1;

/**
 * The CMS SignedData a PAdES signature carries in its /Contents.
 *
 * Built to ZATCA's Security Features standard, section 2.3.4: detached,
 * ECDSA with SHA-256, the signing certificate and its chain in
 * SignedData.certificates, and exactly three signed attributes - content-type (id-data), message-digest
 * and ESS signing-certificate-v2. No signing-time attribute: PAdES baseline
 * claims the time in the signature dictionary's /M and forbids it here.
 *
 * Encoded by hand rather than through an ASN.1 map. The structure is fixed,
 * and the parts copied from the certificate - its issuer and serial - have to
 * be the certificate's own bytes: a Name decoded and re-encoded is not
 * guaranteed to come back identical, and a verifier matches on the bytes.
 */
class CadesSignature
{
    private const ID_DATA = '1.2.840.113549.1.7.1';

    private const ID_SIGNED_DATA = '1.2.840.113549.1.7.2';

    private const ID_CONTENT_TYPE = '1.2.840.113549.1.9.3';

    private const ID_MESSAGE_DIGEST = '1.2.840.113549.1.9.4';

    private const ID_SIGNING_CERTIFICATE_V2 = '1.2.840.113549.1.9.16.2.47';

    private const ID_SHA256 = '2.16.840.1.101.3.4.2.1';

    private const ID_ECDSA_WITH_SHA256 = '1.2.840.10045.4.3.2';

    public function __construct(private readonly EcdsaSigner $signer) {}

    /**
     * @param  list<string>  $chain  PEM certificates above the signing one
     * @return string DER-encoded ContentInfo wrapping the SignedData
     *
     * @throws SigningException when the key is not the certificate's
     */
    public function sign(string $content, string $privateKeyPem, string $certificatePem, array $chain = []): string
    {
        // A signature under some other key verifies against nothing, and a
        // PDF carrying one looks stamped to anyone who does not check.
        if (! openssl_x509_check_private_key($certificatePem, $privateKeyPem)) {
            throw new SigningException('The private key does not belong to the signing certificate.');
        }

        $certificate = $this->der($certificatePem);
        [$issuer, $serial] = $this->issuerAndSerial($certificate);

        // Parameters absent, as RFC 5754 says SHA-256 is to be written.
        $sha256 = self::sequence(self::oid(self::ID_SHA256));

        $attributes = [
            self::attribute(self::ID_CONTENT_TYPE, self::oid(self::ID_DATA)),
            self::attribute(self::ID_MESSAGE_DIGEST, self::octets(hash('sha256', $content, true))),
            // SigningCertificateV2 { certs: [ESSCertIDv2] }. hashAlgorithm is
            // left out because SHA-256 is its DEFAULT, which DER omits.
            self::attribute(self::ID_SIGNING_CERTIFICATE_V2, self::sequence(self::sequence(self::sequence(
                self::octets(hash('sha256', $certificate, true)),
                // IssuerSerial: the issuer as a GeneralName directoryName [4].
                self::sequence(self::sequence(self::tlv(0xA4, $issuer)), $serial),
            )))),
        ];

        // DER orders a SET OF by its members' encodings.
        sort($attributes, SORT_STRING);
        $signedAttributes = implode('', $attributes);

        // Signed as a SET, though it is carried under the [0] tag (RFC 5652, 5.4).
        $signature = base64_decode($this->signer->sign(self::tlv(0x31, $signedAttributes), $privateKeyPem));

        // The chain helps a verifier build the path to ZATCA's anchor. The
        // set is DER, so ordered by encoding; a verifier finds the signer by
        // issuer and serial, not by position.
        $certificates = array_map($this->der(...), $chain);
        $certificates[] = $certificate;
        sort($certificates, SORT_STRING);

        $signerInfo = self::sequence(
            self::integer(1),
            self::sequence($issuer, $serial),
            $sha256,
            self::tlv(0xA0, $signedAttributes),
            self::sequence(self::oid(self::ID_ECDSA_WITH_SHA256)),
            self::octets($signature),
        );

        return self::sequence(
            self::oid(self::ID_SIGNED_DATA),
            self::tlv(0xA0, self::sequence(
                self::integer(1),
                self::tlv(0x31, $sha256),
                // Detached: the content type, and no content.
                self::sequence(self::oid(self::ID_DATA)),
                self::tlv(0xA0, implode('', $certificates)),
                self::tlv(0x31, $signerInfo),
            )),
        );
    }

    /**
     * @throws SigningException
     */
    private function der(string $certificatePem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $certificatePem, $match) !== 1) {
            throw new SigningException('The signing certificate is not a PEM certificate.');
        }

        $der = base64_decode((string) preg_replace('/\s+/', '', $match[1]), true);

        if ($der === false || $der === '') {
            throw new SigningException('The signing certificate is not valid base64.');
        }

        return $der;
    }

    /**
     * The certificate's issuer Name and serialNumber INTEGER, as its bytes.
     *
     * @return array{0: string, 1: string}
     *
     * @throws SigningException
     */
    private function issuerAndSerial(string $certificate): array
    {
        $decoded = ASN1::decodeBER($certificate);
        $tbs = $decoded[0]['content'][0]['content'] ?? null;

        if (! is_array($tbs)) {
            throw new SigningException('The signing certificate could not be read.');
        }

        // version is [0] and optional; a v1 certificate starts at the serial.
        $first = isset($tbs[0]['constant']) ? 1 : 0;
        $slice = static fn (array $node): string => substr($certificate, $node['start'], $node['length']);

        return [$slice($tbs[$first + 2]), $slice($tbs[$first])];
    }

    private static function attribute(string $oid, string $value): string
    {
        return self::sequence(self::oid($oid), self::tlv(0x31, $value));
    }

    private static function sequence(string ...$members): string
    {
        return self::tlv(0x30, implode('', $members));
    }

    private static function oid(string $oid): string
    {
        return self::tlv(0x06, ASN1::encodeOID($oid));
    }

    private static function octets(string $bytes): string
    {
        return self::tlv(0x04, $bytes);
    }

    private static function integer(int $value): string
    {
        return self::tlv(0x02, chr($value));
    }

    private static function tlv(int $tag, string $content): string
    {
        return chr($tag).ASN1::encodeLength(strlen($content)).$content;
    }
}
