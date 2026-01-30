<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

/**
 * ZATCA invoice hash generator.
 *
 * Creates SHA-256 hash of invoice XML for compliance verification.
 * Each invoice hash must be unique and reproducible.
 */
class InvoiceHasher
{
    /**
     * Generate SHA-256 hash of invoice XML.
     *
     * @param string $xml Invoice XML content
     * @return string Base64-encoded hash
     */
    public function hash(string $xml): string
    {
        // Normalize XML (remove whitespace between tags)
        $normalized = $this->normalizeXml($xml);

        // SHA-256 hash, then base64 encode
        $hash = hash('sha256', $normalized, true);

        return base64_encode($hash);
    }

    /**
     * Generate hash from invoice data (without XML).
     * Useful for simple hash generation.
     */
    public function hashFromData(array $data): string
    {
        // Create deterministic string from data
        ksort($data);
        $content = json_encode($data, JSON_UNESCAPED_UNICODE);

        $hash = hash('sha256', $content, true);

        return base64_encode($hash);
    }

    /**
     * Verify hash matches content.
     */
    public function verify(string $xml, string $expectedHash): bool
    {
        return $this->hash($xml) === $expectedHash;
    }

    /**
     * Normalize XML for consistent hashing.
     */
    private function normalizeXml(string $xml): string
    {
        // Remove XML declaration whitespace variations
        $xml = preg_replace('/>\s+</', '><', $xml);

        // Normalize line endings
        $xml = str_replace(["\r\n", "\r"], "\n", $xml);

        return trim($xml);
    }
}
