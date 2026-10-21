<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

/**
 * What ZATCA said about a compliance document, as its owner reads it.
 *
 * The authority answers in schema-validator and rule-engine text - "cvc-
 * complex-type.2.4.b: The content of element 'ext:ExtensionContent' is not
 * complete" - which the portal showed as it came. Each message here gets a
 * sentence in the reader's language, and keeps ZATCA's own text beside it
 * for whoever fixes it.
 */
final class ComplianceFindings
{
    /**
     * @param  array<string, mixed>  $check  one document's result: errors and warnings, as CsidOnboarding keeps them
     * @return list<array{plain: string, raw: string}>
     */
    public static function explain(array $check): array
    {
        $findings = [];

        foreach ([...(array) ($check['errors'] ?? []), ...(array) ($check['warnings'] ?? [])] as $message) {
            $raw = is_array($message)
                ? trim(implode(': ', array_filter([(string) ($message['code'] ?? ''), (string) ($message['message'] ?? '')])))
                : trim((string) $message);

            if ($raw !== '') {
                $findings[] = ['plain' => self::plain($raw), 'raw' => $raw];
            }
        }

        return $findings;
    }

    /**
     * A warning ZATCA accepted an invoice with, as its owner reads it. Shown
     * as it came - "BR-KSA-F-13: [BR-KSA-F-13] - Please recheck Other
     * Seller/Buyer ID..." - in English, even on the Arabic page. Nothing to
     * correct on that invoice: each says what to check for the next one.
     */
    public static function advisory(string $raw): string
    {
        return match (true) {
            // Other Seller/Buyer ID with a run of repeated or consecutive digits.
            str_contains($raw, 'BR-KSA-F-13') => __('ZATCA asks you to check the other identification number of the buyer or seller: it looks like a placeholder, such as a run of repeated or consecutive digits. The invoice was accepted.'),
            // A standard tax invoice states its supply date (KSA-5).
            preg_match('/\bBR-KSA-15\b/', $raw) === 1 => __('ZATCA asks for the supply date on a standard tax invoice. The invoice was accepted; give the supply date on the next one.'),
            // The seller's identification and its type (BT-29).
            preg_match('/\bBR-KSA-08\b/', $raw) === 1 => __('ZATCA asks you to check your company\'s seller identification and its type on the company page. The invoice was accepted.'),
            preg_match('/\b(BR-(?:KSA-)?(?:F-|CL-)?\d+)\b/', $raw, $rule) === 1 => __('ZATCA accepted the invoice and advises checking rule :rule for the next one.', ['rule' => $rule[1]]),
            default => __('ZATCA accepted the invoice with a remark. Its own words are below.'),
        };
    }

    private static function plain(string $raw): string
    {
        return match (true) {
            preg_match('/schema validation|cvc-|XSD/i', $raw) === 1 => __('The document is not in the structure ZATCA requires: part of it is missing or out of place.'),
            preg_match('/\b(401|unauthori[sz]ed)\b/i', $raw) === 1 => __('ZATCA did not accept the compliance certificate and its secret.'),
            preg_match('/\bQR\b/i', $raw) === 1 && preg_match('/^BR-/', $raw) !== 1 => __('The QR code is missing, or does not match the document.'),
            preg_match('/signature|signed properties|certificate/i', $raw) === 1 => __('ZATCA did not accept the document\'s digital signature or certificate.'),
            preg_match('/\bPIH\b|previous invoice hash/i', $raw) === 1 => __('ZATCA did not accept the link to the previous document (its hash).'),
            preg_match('/\b(BR-(?:KSA-)?(?:F-)?\d+)\b/', $raw, $rule) === 1 => __('ZATCA rule :rule was not met.', ['rule' => $rule[1]]),
            default => __('ZATCA refused the document.'),
        };
    }
}
