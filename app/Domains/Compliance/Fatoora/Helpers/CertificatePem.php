<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Helpers;

/**
 * A stored certificate, as a PEM whatever form it was stored in.
 *
 * ZATCA returns a CSID as binarySecurityToken: the certificate's base64 DER,
 * base64-encoded a second time. Onboarding stores that token as it comes,
 * because the token - not the certificate - is what authenticates every later
 * call. Anything that needs the certificate itself (to sign, to read its
 * expiry, to check it) has to unwrap it first, and nothing did: the signer
 * read the token as if it were a PEM, so in production no invoice could be
 * issued under a certificate onboarding had stored.
 *
 * Already a PEM, it is returned as it is - which is what test fixtures and
 * older stored credentials hold.
 */
final class CertificatePem
{
    public static function from(?string $stored): ?string
    {
        if (trim((string) $stored) === '') {
            return null;
        }

        // Byte for byte: the chain identifies a certificate by its hash, so a
        // PEM that came back trimmed would read as a certificate change.
        if (str_contains((string) $stored, 'BEGIN CERTIFICATE')) {
            return $stored;
        }

        $stored = trim((string) $stored);

        // A token decodes to the DER's base64, which is text; a value that is
        // already the DER's base64 decodes to binary, and is the body itself.
        $decoded = base64_decode($stored, true);
        $body = $decoded !== false && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $decoded) === 1
            ? (string) preg_replace('/\s+/', '', $decoded)
            : $stored;

        return "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----\n";
    }
}
