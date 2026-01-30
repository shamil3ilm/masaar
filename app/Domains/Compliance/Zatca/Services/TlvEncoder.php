<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

/**
 * TLV (Tag-Length-Value) encoder for ZATCA QR codes.
 *
 * Encodes invoice data into TLV format as per ZATCA specifications.
 * Each field: 1 byte tag + 1 byte length + variable value bytes.
 */
class TlvEncoder
{
    /**
     * Encode a single TLV field.
     *
     * @param int $tag Tag number (1-9)
     * @param string $value Field value
     * @return string Binary TLV data
     */
    public function encodeTag(int $tag, string $value): string
    {
        $valueBytes = $value;
        $length = strlen($valueBytes);

        return chr($tag) . chr($length) . $valueBytes;
    }

    /**
     * Encode multiple TLV fields and return base64.
     *
     * @param array<int, string> $fields [tag => value]
     * @return string Base64 encoded TLV data
     */
    public function encode(array $fields): string
    {
        $tlvData = '';

        foreach ($fields as $tag => $value) {
            $tlvData .= $this->encodeTag($tag, $value);
        }

        return base64_encode($tlvData);
    }

    /**
     * Decode base64 TLV data back to fields.
     *
     * @param string $base64 Base64 encoded TLV
     * @return array<int, string> [tag => value]
     */
    public function decode(string $base64): array
    {
        $data = base64_decode($base64);
        $fields = [];
        $offset = 0;

        while ($offset < strlen($data)) {
            $tag = ord($data[$offset]);
            $length = ord($data[$offset + 1]);
            $value = substr($data, $offset + 2, $length);

            $fields[$tag] = $value;
            $offset += 2 + $length;
        }

        return $fields;
    }
}
