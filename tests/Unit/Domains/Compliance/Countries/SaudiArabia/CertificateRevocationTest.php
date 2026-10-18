<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Compliance\Countries\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationList;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A revoked certificate must never sign an invoice.
 *
 * Both failure directions matter. Missing a revoked certificate signs tax
 * documents with a dead key; a false match refuses a valid one and stops the
 * taxpayer invoicing at all.
 *
 * These run against a real CRL rather than a hand-written sample, because the
 * two sides disagree in ways only a real one shows: the certificate's serial
 * arrives as hex from openssl_x509_parse(), the CRL's as decimal from
 * phpseclib, and X.509 serials run to 20 octets so neither fits a PHP int.
 *
 * Fixtures: tests/Fixtures/Certificates — serial 1000 revoked, 1001 not.
 *
 * RevocationList is driven directly because the check around it fetches a
 * URL taken from the certificate.
 */
class CertificateRevocationTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../../../Fixtures/Certificates';

    /** Serial of revoked.pem, as openssl_x509_parse() reports it. */
    private const REVOKED_HEX = '1000';

    /** Serial of good.pem. */
    private const GOOD_HEX = '1001';

    public function test_int_cast_yields_zero(): void
    {
        // Documents the defect rather than the fix: this is what the original
        // implementation compared against, for every certificate.
        $serial = '0x4F8A2B1C9D3E6F7A0B1C2D3E4F5A6B7C8D9E0F11';

        $this->assertSame('0', strtoupper(dechex((int) $serial)));
    }

    /**
     * A distribution point may serve either encoding, and which one is not the
     * caller's choice.
     *
     * @return array<string, array{string}>
     */
    public static function crlEncodings(): array
    {
        return ['PEM' => ['crl.pem'], 'DER' => ['crl.der']];
    }

    #[DataProvider('crlEncodings')]
    public function test_revoked_serial_found(string $file): void
    {
        $this->assertNotNull($this->list($file)->revocationOf(self::REVOKED_HEX));
    }

    #[DataProvider('crlEncodings')]
    public function test_good_serial_absent(string $file): void
    {
        $this->assertNull($this->list($file)->revocationOf(self::GOOD_HEX));
    }

    #[DataProvider('crlEncodings')]
    public function test_revocation_date_captured(string $file): void
    {
        $this->assertNotSame('', (string) $this->list($file)->revocationOf(self::REVOKED_HEX));
    }

    /**
     * The certificate's hex and the CRL's decimal name the same number, and
     * this is the comparison the whole check rests on.
     */
    public function test_hex_and_decimal_agree(): void
    {
        $this->assertSame(
            $this->normalize(self::REVOKED_HEX, 16),
            $this->normalize('4096', 10)
        );
    }

    /**
     * The base has to be stated, not guessed. "1000" is a valid spelling in
     * either, and reading the certificate's hex 1000 as decimal normalises it
     * to 3E8 — which matches nothing, so a revoked certificate reads as good.
     */
    public function test_base_is_not_guessed(): void
    {
        $this->assertNotSame(
            $this->normalize('1000', 16),
            $this->normalize('1000', 10)
        );
    }

    public function test_unparseable_crl_yields_nothing(): void
    {
        $this->assertNull(RevocationList::parse('not a CRL'));
    }

    /**
     * openssl_x509_parse() varies on the 0x prefix, letter case and zero
     * padding. All three must compare equal.
     *
     * @return array<string, array{string, string}>
     */
    public static function equivalentSerialProvider(): array
    {
        return [
            '0x prefix vs bare' => ['0x4F8A2B1C', '4F8A2B1C'],
            'lower vs upper' => ['4f8a2b1c', '4F8A2B1C'],
            'zero padded' => ['000A1B2C3D', '0A1B2C3D'],
            'prefixed and padded' => ['0x00FF', 'ff'],
        ];
    }

    #[DataProvider('equivalentSerialProvider')]
    public function test_serial_forms_equal(string $a, string $b): void
    {
        $this->assertSame($this->normalize($a, 16), $this->normalize($b, 16));
    }

    public function test_zero_serial(): void
    {
        $this->assertSame('0', $this->normalize('0x0000', 16));
    }

    /**
     * A list is evidence only from its own CA.
     */
    #[DataProvider('crlEncodings')]
    public function test_list_verifies_against_its_ca(string $file): void
    {
        $this->assertTrue($this->list($file)->signedBy((string) file_get_contents(self::FIXTURES.'/ca.pem')));
        $this->assertFalse($this->list($file)->signedBy((string) file_get_contents(self::FIXTURES.'/good.pem')));
    }

    /**
     * Seven days from issue at most, though this list names a next update
     * ten years on (Security Features Implementation Standard v1.2, section
     * 2.2.1, requirement 14).
     */
    public function test_list_lapses_after_seven_days(): void
    {
        $list = $this->list('crl.der');

        $this->assertTrue($list->usableAt($list->thisUpdate->addDays(6)));
        $this->assertFalse($list->usableAt($list->thisUpdate->addDays(7)));
    }

    private function list(string $file): RevocationList
    {
        $list = RevocationList::parse((string) file_get_contents(self::FIXTURES.'/'.$file));
        $this->assertNotNull($list, "{$file} did not parse");

        return $list;
    }

    private function normalize(string $serial, int $base): string
    {
        return RevocationList::serial($serial, $base);
    }
}
