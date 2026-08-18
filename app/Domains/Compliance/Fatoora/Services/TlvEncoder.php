<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use InvalidArgumentException;

/**
 * TLV (Tag-Length-Value) encoder for ZATCA QR codes.
 *
 * Encodes invoice data into TLV format as per ZATCA specifications.
 * Each field: 1 byte tag + 1 byte length + variable value bytes.
 */
class TlvEncoder
{
    private const MAX_VALUE_LENGTH = 255;

    private const MAX_TAG = 255;

    /**
     * Encode a single TLV field.
     *
     * @param  int  $tag  Tag number (1-255)
     * @param  string  $value  Field value
     * @return string Binary TLV data
     *
     * @throws InvalidArgumentException If tag or value length exceeds limits
     */
    public function encodeTag(int $tag, string $value): string
    {
        if ($tag < 1 || $tag > self::MAX_TAG) {
            throw new InvalidArgumentException(
                sprintf('TLV tag must be between 1 and %d, got %d', self::MAX_TAG, $tag)
            );
        }

        $length = strlen($value);

        if ($length > self::MAX_VALUE_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'TLV value length exceeds maximum of %d bytes (got %d bytes for tag %d)',
                    self::MAX_VALUE_LENGTH,
                    $length,
                    $tag
                )
            );
        }

        return chr($tag).chr($length).$value;
    }

    /**
     * Encode multiple TLV fields and return base64.
     *
     * @param  array<int, string>  $fields  [tag => value]
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
     * @param  string  $base64  Base64 encoded TLV
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
