<?php

declare(strict_types=1);

namespace App\Domains\Auth\Support;

/**
 * Time-based one-time passwords (RFC 6238), as authenticator apps produce them.
 *
 * SHA-1, six digits, thirty-second steps: the defaults every authenticator
 * app assumes when a QR code does not say otherwise. Written here rather than
 * taken from a package because it is forty lines and one hash, and the RFC's
 * own test vectors pin it (TotpTest).
 */
final class Totp
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new shared secret: twenty random bytes, base32, as an app takes it.
     */
    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The code for a moment in time.
     */
    public static function code(string $secret, int $timestamp): string
    {
        return self::codeAt($secret, intdiv($timestamp, self::PERIOD));
    }

    /**
     * The time step a code belongs to, or null when it is not valid.
     *
     * One step either side is accepted, for a phone whose clock has drifted.
     * The step is returned so the caller can refuse it a second time: a code
     * is good for its whole window, and without that it could be replayed.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): ?int
    {
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }

        $step = intdiv($timestamp ?? time(), self::PERIOD);

        foreach ([$step, $step - 1, $step + 1] as $candidate) {
            if (hash_equals(self::codeAt($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The otpauth:// URI an authenticator app reads from the QR code.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private static function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);

        // Dynamic truncation, RFC 4226 section 5.3.
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private static function base32Decode(string $encoded): string
    {
        $bits = '';

        foreach (str_split(strtoupper(rtrim($encoded, '='))) as $character) {
            $position = strpos(self::ALPHABET, $character);

            if ($position !== false) {
                $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
            }
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
