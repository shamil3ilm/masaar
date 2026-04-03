<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Helpers;

/**
 * Text Normalizer for ZATCA Compliance.
 *
 * Handles Arabic text normalization, encoding validation,
 * and XML-safe string processing.
 *
 * This addresses:
 * - Arabic name normalization (Al / Al- / Bin / spacing)
 * - UTF-8 encoding validation
 * - XML character escaping
 * - Whitespace normalization
 */
final class TextNormalizer
{
    /**
     * Arabic prefixes to normalize.
     */
    private const ARABIC_PREFIXES = [
        'ال',     // Al
        'آل',     // Aal
        'بن',     // Bin
        'إبن',    // Ibn
        'أبو',    // Abu
        'عبد',    // Abd
    ];

    /**
     * Arabic diacritics (tashkeel) to remove.
     */
    private const ARABIC_DIACRITICS = [
        "\u{064B}", // Fathatan
        "\u{064C}", // Dammatan
        "\u{064D}", // Kasratan
        "\u{064E}", // Fatha
        "\u{064F}", // Damma
        "\u{0650}", // Kasra
        "\u{0651}", // Shadda
        "\u{0652}", // Sukun
    ];

    /**
     * Normalize text for ZATCA XML.
     */
    public static function normalize(string $text): string
    {
        // 1. Validate UTF-8
        $text = self::ensureUtf8($text);

        // 2. Remove control characters (except newlines and tabs)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // 3. Normalize whitespace
        $text = self::normalizeWhitespace($text);

        // 4. Normalize Arabic text
        $text = self::normalizeArabic($text);

        return trim($text);
    }

    /**
     * Normalize text specifically for names (seller/buyer).
     */
    public static function normalizeName(string $name): string
    {
        $name = self::normalize($name);

        // Normalize Arabic name prefixes
        $name = self::normalizeArabicNamePrefixes($name);

        // Collapse multiple spaces
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    /**
     * Normalize Arabic text.
     */
    public static function normalizeArabic(string $text): string
    {
        if (!self::containsArabic($text)) {
            return $text;
        }

        // Remove diacritics (tashkeel)
        $text = str_replace(self::ARABIC_DIACRITICS, '', $text);

        // Normalize Alef variations to standard Alef
        $text = preg_replace('/[أإآ]/u', 'ا', $text);

        // Normalize Teh Marbuta to Heh
        $text = str_replace('ة', 'ه', $text);

        // Normalize Yeh variations
        $text = preg_replace('/[ىی]/u', 'ي', $text);

        return $text;
    }

    /**
     * Normalize Arabic name prefixes for consistent matching.
     */
    public static function normalizeArabicNamePrefixes(string $name): string
    {
        // Normalize "Al" prefix variations
        $name = preg_replace('/\bال\s*/u', 'ال', $name);       // Remove space after Al
        $name = preg_replace('/\bAl[\s-]*/i', 'Al ', $name);   // Normalize English "Al-" or "Al "

        // Normalize "Bin/Ibn" variations
        $name = preg_replace('/\bبن\s*/u', 'بن ', $name);
        $name = preg_replace('/\b(Bin|Ibn)[\s-]*/i', 'Bin ', $name);

        // Normalize "Abd" variations
        $name = preg_replace('/\bعبد\s*/u', 'عبد ', $name);
        $name = preg_replace('/\bAbd[\s-]*/i', 'Abd ', $name);

        return $name;
    }

    /**
     * Check if text contains Arabic characters.
     */
    public static function containsArabic(string $text): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }

    /**
     * Ensure text is valid UTF-8.
     */
    public static function ensureUtf8(string $text): string
    {
        // Check if already valid UTF-8
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // Try to convert from common encodings
        $encodings = ['ISO-8859-1', 'Windows-1252', 'ISO-8859-6'];

        foreach ($encodings as $encoding) {
            $converted = @mb_convert_encoding($text, 'UTF-8', $encoding);
            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        // Last resort: remove invalid bytes
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * Normalize whitespace (spaces, tabs, newlines).
     */
    public static function normalizeWhitespace(string $text): string
    {
        // Convert all whitespace to single spaces
        $text = preg_replace('/[\r\n\t]+/', ' ', $text);

        // Collapse multiple spaces
        $text = preg_replace('/\s{2,}/', ' ', $text);

        return $text;
    }

    /**
     * Make text safe for XML (escape special characters).
     */
    public static function escapeForXml(string $text): string
    {
        // First normalize
        $text = self::normalize($text);

        // Then escape for XML
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validate that text is safe for XML element content.
     *
     * @return array{valid: bool, errors: string[]}
     */
    public static function validateForXml(string $text): array
    {
        $errors = [];

        // Check UTF-8 validity
        if (!mb_check_encoding($text, 'UTF-8')) {
            $errors[] = 'Text is not valid UTF-8';
        }

        // Check for XML 1.0 restricted characters
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) {
            $errors[] = 'Text contains XML 1.0 restricted characters';
        }

        // Check for null bytes
        if (str_contains($text, "\0")) {
            $errors[] = 'Text contains null bytes';
        }

        // Check length
        if (mb_strlen($text) > 1000000) {
            $errors[] = 'Text exceeds maximum length (1MB)';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Truncate text to max length while preserving UTF-8 integrity.
     */
    public static function truncate(string $text, int $maxLength, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - mb_strlen($suffix)) . $suffix;
    }

    /**
     * Clean VAT number (remove spaces, dashes, convert to uppercase).
     */
    public static function normalizeVatNumber(string $vatNumber): string
    {
        // Remove all non-alphanumeric characters
        $vatNumber = preg_replace('/[^A-Za-z0-9]/', '', $vatNumber);

        // Convert to uppercase
        return strtoupper($vatNumber);
    }

    /**
     * Validate Saudi VAT number format.
     * Saudi VAT: 15 digits, starts with 3
     *
     * @return array{valid: bool, error: ?string}
     */
    public static function validateSaudiVatNumber(string $vatNumber): array
    {
        $vatNumber = self::normalizeVatNumber($vatNumber);

        if (strlen($vatNumber) !== 15) {
            return ['valid' => false, 'error' => 'VAT number must be 15 digits'];
        }

        if (!ctype_digit($vatNumber)) {
            return ['valid' => false, 'error' => 'VAT number must contain only digits'];
        }

        if ($vatNumber[0] !== '3') {
            return ['valid' => false, 'error' => 'Saudi VAT numbers must start with 3'];
        }

        // Validate checksum (Luhn-like algorithm)
        if (!self::validateVatChecksum($vatNumber)) {
            return ['valid' => false, 'error' => 'Invalid VAT number checksum'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Validate VAT number checksum using Luhn algorithm.
     */
    private static function validateVatChecksum(string $vatNumber): bool
    {
        $sum = 0;
        $length = strlen($vatNumber);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $vatNumber[$i];

            if (($length - $i) % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
