<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Compliance\Fatoora;

use App\Domains\Compliance\Fatoora\Services\CertificateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression guard for H-3 — checkCrl() compared against
 * strtoupper(dechex((int) $serialNumber)).
 *
 * openssl_x509_parse() returns the serial as 0x-prefixed hex, and casting
 * that to int yields 0, so the comparison string was literally "0". Two
 * consequences, both live:
 *
 *   - a genuinely revoked certificate never matched, so it was accepted
 *     for signing, silently
 *   - the regex was unanchored, so "Serial Number: 0..." matched any CRL
 *     entry beginning with zero, reporting valid certificates as revoked
 *
 * These tests drive the serial handling directly, because the surrounding
 * method shells out to `openssl crl` and fetches a URL.
 */
class CertificateRevocationTest extends TestCase
{
    /**
     * A CRL as `openssl crl -text` prints one.
     */
    private const CRL_OUTPUT = <<<'TXT'
    Certificate Revocation List (CRL):
            Version 2 (0x1)
        Revoked Certificates:
            Serial Number: 4F8A2B1C9D3E6F7A0B1C2D3E4F5A6B7C8D9E0F11
                Revocation Date: Jan  5 09:30:00 2026 GMT
            Serial Number: 0A1B2C3D
                Revocation Date: Feb 11 12:00:00 2026 GMT
            Serial Number: 00FF
                Revocation Date: Mar  2 08:15:00 2026 GMT
    TXT;

    public function test_the_old_cast_collapses_a_real_serial_to_zero(): void
    {
        // Documents the defect rather than the fix: this is what the previous
        // implementation compared against, for every certificate.
        $serial = '0x4F8A2B1C9D3E6F7A0B1C2D3E4F5A6B7C8D9E0F11';

        $this->assertSame('0', strtoupper(dechex((int) $serial)));
    }

    public function test_revoked_serial_is_found(): void
    {
        $revoked = $this->revokedSerials();

        $this->assertArrayHasKey('4F8A2B1C9D3E6F7A0B1C2D3E4F5A6B7C8D9E0F11', $revoked);
        $this->assertSame('Jan  5 09:30:00 2026 GMT', $revoked['4F8A2B1C9D3E6F7A0B1C2D3E4F5A6B7C8D9E0F11']);
    }

    public function test_every_crl_entry_is_captured(): void
    {
        $this->assertCount(3, $this->revokedSerials());
    }

    public function test_certificate_not_on_the_list_is_not_reported_revoked(): void
    {
        $this->assertArrayNotHasKey(
            $this->normalize('0xDEADBEEFDEADBEEFDEADBEEFDEADBEEFDEADBEEF'),
            $this->revokedSerials()
        );
    }

    /**
     * The old unanchored regex matched a prefix, so a certificate whose serial
     * merely starts with a revoked serial's digits was reported revoked.
     */
    public function test_a_prefix_of_a_revoked_serial_does_not_match(): void
    {
        $this->assertArrayNotHasKey($this->normalize('0x4F8A'), $this->revokedSerials());
        $this->assertArrayNotHasKey($this->normalize('0x0A1B'), $this->revokedSerials());
    }

    /**
     * openssl_x509_parse() and `openssl crl -text` disagree about the 0x
     * prefix, letter case and zero padding. All three must compare equal.
     */
    #[DataProvider('equivalentSerialProvider')]
    public function test_serial_forms_normalise_to_the_same_value(string $a, string $b): void
    {
        $this->assertSame($this->normalize($a), $this->normalize($b));
    }

    public static function equivalentSerialProvider(): array
    {
        return [
            '0x prefix vs bare' => ['0x4F8A2B1C', '4F8A2B1C'],
            'lower vs upper' => ['4f8a2b1c', '4F8A2B1C'],
            'zero padded' => ['000A1B2C3D', '0A1B2C3D'],
            'prefixed and padded' => ['0x00FF', 'ff'],
        ];
    }

    public function test_all_zero_serial_normalises_to_zero(): void
    {
        $this->assertSame('0', $this->normalize('0x0000'));
    }

    /**
     * A padded CRL entry must still be findable from the certificate's form.
     */
    public function test_zero_padded_crl_entry_matches_unpadded_certificate_serial(): void
    {
        $this->assertArrayHasKey($this->normalize('0xFF'), $this->revokedSerials());
    }

    private function revokedSerials(): array
    {
        return $this->call('revokedSerialsFrom', self::CRL_OUTPUT);
    }

    private function normalize(string $serial): string
    {
        return $this->call('normalizeSerial', $serial);
    }

    private function call(string $method, string $argument): mixed
    {
        $reflection = new ReflectionMethod(CertificateService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new CertificateService(), $argument);
    }
}
