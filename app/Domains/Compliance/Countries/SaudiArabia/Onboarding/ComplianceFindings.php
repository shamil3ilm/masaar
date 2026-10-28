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
     * A warning ZATCA accepted an invoice with, as its owner reads it. It
     * came as "BR-KSA-63: [BR-KSA-63] - ..." in English, even on the Arabic
     * page, and was shown as "check rule BR-KSA-63": now the rule's meaning
     * (RuleMeanings), with its code after for whoever looks it up. Nothing
     * to correct on that invoice: it says what to check on the next one.
     */
    public static function advisory(string $raw): string
    {
        $rule = RuleMeanings::code($raw);

        return $rule === null
            ? __('ZATCA accepted the invoice with a remark. Its own words are below.')
            : __('Accepted. ZATCA notes: :meaning Check this on your next invoice. (:rule)', ['meaning' => RuleMeanings::explain($rule), 'rule' => $rule]);
    }

    /**
     * A rule an invoice was rejected for, as its owner reads it.
     */
    public static function rejection(?string $code, string $message): string
    {
        // A rule code first; otherwise what the words say (a schema error, the
        // signature, the hash), as the onboarding checks read them.
        return self::plain(trim(($code ?? '').': '.$message, ': '));
    }

    private static function plain(string $raw): string
    {
        return match (true) {
            preg_match('/schema validation|cvc-|XSD/i', $raw) === 1 => __('The document is not in the structure ZATCA requires: part of it is missing or out of place.'),
            preg_match('/\b(401|unauthori[sz]ed)\b/i', $raw) === 1 => __('ZATCA did not accept the compliance certificate and its secret.'),
            preg_match('/\bQR\b/i', $raw) === 1 && preg_match('/^BR-/', $raw) !== 1 => __('The QR code is missing, or does not match the document.'),
            preg_match('/signature|signed properties|certificate/i', $raw) === 1 => __('ZATCA did not accept the document\'s digital signature or certificate.'),
            preg_match('/\bPIH\b|previous invoice hash/i', $raw) === 1 => __('ZATCA did not accept the link to the previous document (its hash).'),
            ($rule = RuleMeanings::code($raw)) !== null => RuleMeanings::explain($rule).' ('.$rule.')',
            default => __('ZATCA refused the document.'),
        };
    }
}
