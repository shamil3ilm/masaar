<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use App\Domains\Compliance\Fatoora\Support\Der;
use RuntimeException;

/**
 * A certificate request ZATCA accepts, without the SDK.
 *
 * The SDK is a licensed download, so anything that needs it cannot run in CI
 * and cannot run on a machine that has not been given it. Onboarding needed it
 * for exactly one step, and the fallback that stood in produced a request with
 * no extensions — which the authority refuses with "Invalid Request" several
 * steps later, having announced "CSR generated" at the time.
 *
 * Two extensions are what the SDK adds and OpenSSL's configuration file cannot
 * express:
 *
 *   1.3.6.1.4.1.311.20.2  the certificate template name, which selects the
 *                         authority's environment
 *   2.5.29.17             subjectAltName, carrying a directory name whose
 *                         relative names are the EGS serial number, the VAT
 *                         number, the invoice types this certificate may sign,
 *                         the address and the business category
 *
 * The bytes here were derived from a request the SDK produced and are checked
 * against one in the tests: same key in, same request info out.
 */
class CsrBuilder
{
    /** Selects which of ZATCA's environments the certificate is for. */
    public const TEMPLATE_SANDBOX = 'TSTZATCA-Code-Signing';

    public const TEMPLATE_SIMULATION = 'PREZATCA-Code-Signing';

    public const TEMPLATE_PRODUCTION = 'ZATCA-Code-Signing';

    private const OID_TEMPLATE = '1.3.6.1.4.1.311.20.2';

    private const OID_SUBJECT_ALT_NAME = '2.5.29.17';

    private const OID_EXTENSION_REQUEST = '1.2.840.113549.1.9.14';

    private const OID_ECDSA_SHA256 = '1.2.840.10045.4.3.2';

    /**
     * @param  string  $privateKeyPem  an EC key on secp256k1
     * @return string the request, PEM encoded
     */
    public function build(CsrData $data, string $privateKeyPem, string $template = self::TEMPLATE_SIMULATION): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new RuntimeException('Private key could not be read: '.openssl_error_string());
        }

        $info = Der::sequence(
            Der::integer(0),
            $this->subject($data),
            $this->publicKeyInfo($key),
            Der::context(0, $this->extensionRequest($data, $template)),
        );

        if (! openssl_sign($info, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the request: '.openssl_error_string());
        }

        $request = Der::sequence(
            Der::raw($info),
            Der::sequence(Der::oid(self::OID_ECDSA_SHA256)),
            Der::bits($signature),
        );

        return "-----BEGIN CERTIFICATE REQUEST-----\n"
            .chunk_split(base64_encode($request), 64, "\n")
            ."-----END CERTIFICATE REQUEST-----\n";
    }

    /**
     * C, OU, O, CN — in that order, which is the order the SDK emits.
     */
    private function subject(CsrData $data): string
    {
        return Der::sequence(
            $this->rdn('2.5.4.6', Der::printable('SA')),
            $this->rdn('2.5.4.11', Der::utf8($data->organizationUnit)),
            $this->rdn('2.5.4.10', Der::utf8($data->organizationName)),
            $this->rdn('2.5.4.3', Der::utf8($data->commonName)),
        );
    }

    /**
     * SubjectPublicKeyInfo, taken from the key rather than rebuilt.
     */
    private function publicKeyInfo(mixed $key): string
    {
        $details = openssl_pkey_get_details($key);
        $pem = $details['key'] ?? '';

        if ($pem === '') {
            throw new RuntimeException('Key carries no public half.');
        }

        $der = base64_decode(str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n"],
            '',
            $pem
        ), true);

        if ($der === false) {
            throw new RuntimeException('Public key is not valid base64.');
        }

        return Der::raw($der);
    }

    private function extensionRequest(CsrData $data, string $template): string
    {
        $extensions = Der::sequence(
            Der::sequence(
                Der::oid(self::OID_TEMPLATE),
                Der::octet(Der::utf8($template)),
            ),
            Der::sequence(
                Der::oid(self::OID_SUBJECT_ALT_NAME),
                Der::octet($this->subjectAltName($data)),
            ),
        );

        return Der::sequence(
            Der::oid(self::OID_EXTENSION_REQUEST),
            Der::set($extensions),
        );
    }

    /**
     * GeneralNames holding one directoryName, tagged [4].
     *
     * SN is 2.5.4.4 here. That OID is "surname" by name, and ZATCA reads the
     * EGS serial number out of it — the request is refused if it is put
     * anywhere else.
     */
    private function subjectAltName(CsrData $data): string
    {
        $directory = Der::sequence(
            $this->rdn('2.5.4.4', Der::utf8($data->serialNumber)),
            $this->rdn('0.9.2342.19200300.100.1.1', Der::utf8($data->vatNumber)),
            $this->rdn('2.5.4.12', Der::utf8($data->getInvoiceTypeCode())),
            $this->rdn('2.5.4.26', Der::utf8($data->location)),
            $this->rdn('2.5.4.15', Der::utf8($data->industry)),
        );

        return Der::sequence(Der::context(4, $directory));
    }

    private function rdn(string $oid, string $value): string
    {
        return Der::set(Der::sequence(Der::oid($oid), $value));
    }
}
