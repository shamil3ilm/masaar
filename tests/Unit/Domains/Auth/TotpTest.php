<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Auth;

use App\Domains\Auth\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Time-based one-time passwords, against the RFC's own vectors.
 *
 * RFC 6238 Appendix B gives the SHA-1 codes for the ASCII secret
 * "12345678901234567890" at fixed times, as eight digits. Authenticator apps
 * show six, which are the last six of the same number.
 */
class TotpTest extends TestCase
{
    /** "12345678901234567890" in base32, as an authenticator app is given it. */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function vectors(): array
    {
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
        ];
    }

    #[DataProvider('vectors')]
    public function test_codes_match_the_rfc(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::code(self::SECRET, $time));
    }

    public function test_the_current_code_verifies(): void
    {
        $this->assertNotNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, 1234567890), 1234567890));
    }

    /**
     * One step either side, for a phone whose clock is a little out.
     */
    public function test_a_neighbouring_step_verifies(): void
    {
        $this->assertNotNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, 1234567890 - 30), 1234567890));
        $this->assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, 1234567890 - 90), 1234567890));
    }

    public function test_a_wrong_code_fails(): void
    {
        $this->assertNull(Totp::verify(self::SECRET, '000000', 1234567890));
        $this->assertNull(Totp::verify(self::SECRET, 'abc', 1234567890));
    }

    public function test_a_secret_is_twenty_random_bytes(): void
    {
        $secret = Totp::secret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, Totp::secret());
    }

    public function test_the_uri_names_the_issuer(): void
    {
        $uri = Totp::uri(self::SECRET, 'ops@example.test', 'Acme');

        $this->assertStringStartsWith('otpauth://totp/Acme:ops%40example.test?', $uri);
        $this->assertStringContainsString('secret='.self::SECRET, $uri);
        $this->assertStringContainsString('issuer=Acme', $uri);
    }
}
