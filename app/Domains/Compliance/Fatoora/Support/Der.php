<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Support;

/**
 * Just enough DER to write a certificate request.
 *
 * ZATCA's CSR carries two extensions OpenSSL cannot express through a
 * configuration file and phpseclib does not model: a Microsoft certificate
 * template name, and a subjectAltName holding a directory name whose relative
 * names are the taxpayer's registration details. Producing them means writing
 * the bytes.
 *
 * Encoding only. The lengths are short-form under 128 and long-form above,
 * which is all a request of this size needs.
 */
final class Der
{
    public static function sequence(string ...$parts): string
    {
        return self::tagged(0x30, implode('', $parts));
    }

    public static function set(string ...$parts): string
    {
        return self::tagged(0x31, implode('', $parts));
    }

    public static function integer(int $value): string
    {
        return self::tagged(0x02, chr($value));
    }

    public static function utf8(string $value): string
    {
        return self::tagged(0x0C, $value);
    }

    public static function printable(string $value): string
    {
        return self::tagged(0x13, $value);
    }

    public static function octet(string $value): string
    {
        return self::tagged(0x04, $value);
    }

    /**
     * A BIT STRING with no unused bits, which is how signatures and keys go in.
     */
    public static function bits(string $value): string
    {
        return self::tagged(0x03, "\x00".$value);
    }

    /**
     * A context-specific constructed tag, [$number].
     */
    public static function context(int $number, string $value): string
    {
        return self::tagged(0xA0 | $number, $value);
    }

    /**
     * An OBJECT IDENTIFIER from its dotted form.
     */
    public static function oid(string $dotted): string
    {
        $parts = array_map('intval', explode('.', $dotted));
        $body = chr($parts[0] * 40 + $parts[1]);

        foreach (array_slice($parts, 2) as $part) {
            $chunk = chr($part & 0x7F);
            $part >>= 7;

            while ($part > 0) {
                $chunk = chr(($part & 0x7F) | 0x80).$chunk;
                $part >>= 7;
            }

            $body .= $chunk;
        }

        return self::tagged(0x06, $body);
    }

    /**
     * Bytes that are already DER, passed through unchanged.
     */
    public static function raw(string $der): string
    {
        return $der;
    }

    private static function tagged(int $tag, string $body): string
    {
        return chr($tag).self::length(strlen($body)).$body;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';

        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
