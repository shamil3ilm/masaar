<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Gateways;

use Illuminate\Http\Request;

/**
 * The signature on the provider's callbacks: HMAC-SHA256 over
 * "{timestamp}.{raw body}" with PEPPOL_ASP_WEBHOOK_SECRET, hex encoded.
 *
 * The routes it guards are public, and an unsigned status callback could
 * mark any document delivered or rejected, an unsigned inbound one plant an
 * invoice in any organization. The timestamp is inside the signature, so a
 * captured callback cannot be replayed once it is older than the tolerance;
 * inside it, a replay changes nothing, because applying a status and storing
 * an inbound document are both idempotent.
 *
 * A provider that signs differently needs only this class adapted.
 */
final class WebhookSignature
{
    public const SIGNATURE_HEADER = 'X-Signature';

    public const TIMESTAMP_HEADER = 'X-Signature-Timestamp';

    /** Seconds either side of now a callback may be stamped. */
    public const TOLERANCE = 300;

    public function __construct(private readonly ?string $secret) {}

    public function verify(Request $request): bool
    {
        // No secret, no callbacks: refusing everything is the only answer that
        // does not trust an unsigned request.
        if ($this->secret === null || $this->secret === '') {
            return false;
        }

        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER, '');
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        // Some providers prefix the algorithm, "sha256=<hex>".
        $signature = strtolower((string) preg_replace('/^sha256=/i', '', $signature));

        return hash_equals($this->sign($timestamp, $request->getContent()), $signature);
    }

    public function sign(string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, (string) $this->secret);
    }
}
