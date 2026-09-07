<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use App\Domains\Compliance\Fatoora\Services\CsrBuilder;
use phpseclib3\Crypt\EC;
use Tests\TestCase;

/**
 * The request has to carry what ZATCA reads out of it.
 *
 * Onboarding needed the licensed SDK for this one step, and the fallback that
 * stood in built a request with no extensions at all — announcing "CSR
 * generated" and leaving the authority to refuse it with "Invalid Request"
 * three steps later, in a message naming neither the extensions nor this
 * command.
 *
 * The bytes this produces were checked against a request the SDK made from the
 * same key: the two are identical. What is asserted here is the structure that
 * made them identical, so a change to it fails before the authority sees it.
 */
class CsrBuilderTest extends TestCase
{
    private const TEMPLATE_OID = "\x06\x09\x2b\x06\x01\x04\x01\x82\x37\x14\x02";

    private string $key;

    private string $der;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = EC::createKey('secp256k1')->toString('PKCS8');
        $this->der = $this->decode(app(CsrBuilder::class)->build($this->data(), $this->key));
    }

    public function test_openssl_can_read_it(): void
    {
        $subject = openssl_csr_get_subject($this->pem());

        $this->assertSame('SA', $subject['C']);
        $this->assertSame('Acme Trading', $subject['O']);
        $this->assertSame('IT Department', $subject['OU']);
        $this->assertSame('EGS1-TEST-001', $subject['CN']);
    }

    /**
     * The template selects the authority's environment, and a request without
     * it is refused.
     */
    public function test_it_carries_the_template(): void
    {
        $this->assertStringContainsString(self::TEMPLATE_OID, $this->der, 'The template OID is missing.');
        $this->assertStringContainsString('PREZATCA-Code-Signing', $this->der);

        $production = $this->decode(app(CsrBuilder::class)->build(
            $this->data(), $this->key, CsrBuilder::TEMPLATE_PRODUCTION
        ));

        $this->assertStringContainsString('ZATCA-Code-Signing', $production);
        $this->assertStringNotContainsString('PREZATCA', $production);
    }

    /**
     * The five relative names inside subjectAltName. ZATCA reads the serial
     * number out of 2.5.4.4 — "surname" by name — and refuses the request if
     * it is anywhere else.
     */
    public function test_it_carries_the_registration_details(): void
    {
        foreach ([
            "\x06\x03\x55\x04\x04" => '1-Solution|2-1.0|3-abc',   // SN
            "\x06\x0a\x09\x92\x26\x89\x93\xf2\x2c\x64\x01\x01" => '399999999900003', // UID
            "\x06\x03\x55\x04\x0c" => '1100',                      // title
            "\x06\x03\x55\x04\x1a" => 'Riyadh',                    // registeredAddress
            "\x06\x03\x55\x04\x0f" => 'Information Technology',    // businessCategory
        ] as $oid => $value) {
            $this->assertStringContainsString($oid, $this->der, 'A subjectAltName OID is missing.');
            $this->assertStringContainsString($value, $this->der);
        }
    }

    /**
     * The invoice types the certificate may sign travel in the request, so a
     * certificate issued against it can only sign what was asked for.
     */
    public function test_the_invoice_types_travel_with_it(): void
    {
        $simplified = $this->decode(app(CsrBuilder::class)->build(
            $this->data(standard: false, simplified: true), $this->key
        ));

        $this->assertStringContainsString("\x06\x03\x55\x04\x0c\x0c\x040100", $simplified);
    }

    /**
     * A request is only a request if it is signed by the key it names.
     */
    public function test_it_is_self_signed(): void
    {
        [$info, $signature] = $this->split($this->der);

        $public = openssl_pkey_get_details(openssl_pkey_get_private($this->key))['key'];

        $this->assertSame(1, openssl_verify($info, $signature, $public, OPENSSL_ALGO_SHA256));
    }

    private function data(bool $standard = true, bool $simplified = true): CsrData
    {
        return new CsrData(
            organizationName: 'Acme Trading',
            organizationUnit: 'IT Department',
            commonName: 'EGS1-TEST-001',
            vatNumber: '399999999900003',
            serialNumber: '1-Solution|2-1.0|3-abc',
            location: 'Riyadh',
            industry: 'Information Technology',
            invoiceTypesStandard: $standard,
            invoiceTypesSimplified: $simplified,
        );
    }

    private function pem(): string
    {
        return app(CsrBuilder::class)->build($this->data(), $this->key);
    }

    private function decode(string $pem): string
    {
        return (string) base64_decode((string) preg_replace('#-----[^-]+-----|\s#', '', $pem));
    }

    /**
     * @return array{string, string} the signed info, and the signature over it
     */
    private function split(string $der): array
    {
        $read = function (string $buf, int &$pos): array {
            $tag = ord($buf[$pos]);
            $length = ord($buf[$pos + 1]);
            $pos += 2;

            if ($length > 0x80) {
                $count = $length - 0x80;
                $length = 0;

                for ($i = 0; $i < $count; $i++) {
                    $length = ($length << 8) | ord($buf[$pos + $i]);
                }

                $pos += $count;
            }

            $start = $pos;
            $pos += $length;

            return [$tag, substr($buf, $start, $length), $start];
        };

        $outer = 0;
        [, $body] = $read($der, $outer);

        // the info element, header included, is what was signed
        $inner = 0;
        $read($body, $inner);
        $infoWithHeader = substr($body, 0, $inner);

        $read($body, $inner);                       // signature algorithm
        [, $bits] = $read($body, $inner);           // BIT STRING

        return [$infoWithHeader, substr($bits, 1)];
    }
}
