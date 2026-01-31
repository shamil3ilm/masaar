<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhook Payload Builder.
 *
 * Builds webhook payloads with replay protection fields:
 * - event_id: Unique identifier for deduplication
 * - occurred_at: When the event happened
 * - delivered_at: When webhook was sent
 * - signature: HMAC-SHA256 signature for verification
 * - idempotency_key: Key for idempotent processing
 *
 * Consumer requirements (enforced client-side):
 * - Verify signature with webhook secret
 * - Check event_id uniqueness
 * - Validate timestamp freshness (reject if > 5 minutes old)
 * - Use idempotency_key for deduplication
 *
 * @see docs/COMPLIANCE-POLICIES.md Section 10: Webhook Replay Protection
 */
class WebhookPayloadBuilder
{
    /**
     * Maximum event age in seconds for freshness validation.
     * Consumers should reject events older than this.
     */
    public const MAX_EVENT_AGE_SECONDS = 300; // 5 minutes

    /**
     * Signature algorithm.
     */
    private const SIGNATURE_ALGORITHM = 'sha256';

    /**
     * Event types.
     */
    public const EVENT_INVOICE_CREATED = 'invoice.created';
    public const EVENT_INVOICE_SIGNED = 'invoice.signed';
    public const EVENT_INVOICE_SUBMITTED = 'invoice.submitted';
    public const EVENT_INVOICE_CLEARED = 'invoice.cleared';
    public const EVENT_INVOICE_REPORTED = 'invoice.reported';
    public const EVENT_INVOICE_REJECTED = 'invoice.rejected';
    public const EVENT_CERTIFICATE_EXPIRING = 'certificate.expiring';
    public const EVENT_CERTIFICATE_RENEWED = 'certificate.renewed';
    public const EVENT_QUEUE_ALERT = 'queue.alert';
    public const EVENT_CIRCUIT_BREAKER = 'circuit_breaker.state_change';

    /**
     * Build a webhook payload with replay protection.
     *
     * @param string $eventType Event type (e.g., 'invoice.cleared')
     * @param array $data Event payload data
     * @param string $webhookSecret Organization's webhook secret for signing
     * @param string|null $idempotencyKey Optional idempotency key (generated if not provided)
     * @return array Complete webhook payload ready for delivery
     * @throws \InvalidArgumentException If eventType or webhookSecret is empty
     */
    public function build(
        string $eventType,
        array $data,
        string $webhookSecret,
        ?string $idempotencyKey = null
    ): array {
        // Validate required parameters
        if (empty($eventType)) {
            throw new \InvalidArgumentException('Event type is required');
        }

        if (empty($webhookSecret)) {
            throw new \InvalidArgumentException('Webhook secret is required for signing');
        }

        if (strlen($webhookSecret) < 32) {
            Log::warning('Webhook secret is shorter than recommended 32 characters', [
                'length' => strlen($webhookSecret),
            ]);
        }

        $eventId = 'evt_' . Str::uuid()->toString();
        $occurredAt = now()->toIso8601String();
        $deliveredAt = now()->toIso8601String();
        $idempotencyKey = $idempotencyKey ?? 'idem_' . bin2hex(random_bytes(16));

        $payload = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'delivered_at' => $deliveredAt,
            'idempotency_key' => $idempotencyKey,
            'data' => $data,
            'api_version' => '2026-01-31',
        ];

        // Generate signature
        $payload['signature'] = $this->generateSignature($payload, $webhookSecret);

        return $payload;
    }

    /**
     * Generate HMAC-SHA256 signature for payload.
     *
     * Signature covers: event_id + event_type + occurred_at + data (JSON)
     *
     * @param array $payload Payload to sign
     * @param string $secret Webhook secret
     * @return string Signature in format 'sha256=<hex>'
     */
    public function generateSignature(array $payload, string $secret): string
    {
        // Build canonical string to sign
        $dataToSign = implode('.', [
            $payload['event_id'],
            $payload['event_type'],
            $payload['occurred_at'],
            json_encode($payload['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $signature = hash_hmac(self::SIGNATURE_ALGORITHM, $dataToSign, $secret);

        return self::SIGNATURE_ALGORITHM . '=' . $signature;
    }

    /**
     * Verify a webhook signature.
     *
     * Use this on the receiving end to validate webhook authenticity.
     *
     * @param array $payload Received payload
     * @param string $signature Received signature
     * @param string $secret Webhook secret
     * @return bool True if signature is valid
     */
    public function verifySignature(array $payload, string $signature, string $secret): bool
    {
        $expectedSignature = $this->generateSignature($payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Validate event freshness.
     *
     * @param string $occurredAt ISO8601 timestamp
     * @param int $maxAgeSeconds Maximum age in seconds (default: 300 = 5 minutes)
     * @return array{fresh: bool, age_seconds: int, recommendation: string|null}
     */
    public function validateFreshness(string $occurredAt, int $maxAgeSeconds = self::MAX_EVENT_AGE_SECONDS): array
    {
        try {
            $eventTime = new \DateTimeImmutable($occurredAt);
        } catch (\Exception $e) {
            return [
                'fresh' => false,
                'age_seconds' => -1,
                'recommendation' => 'Invalid timestamp format',
            ];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ageSeconds = $now->getTimestamp() - $eventTime->getTimestamp();

        // Check for future events (clock skew)
        if ($ageSeconds < 0) {
            $skewSeconds = abs($ageSeconds);
            // Allow up to 30 seconds clock skew for future events
            if ($skewSeconds <= 30) {
                return [
                    'fresh' => true,
                    'age_seconds' => 0,
                    'recommendation' => 'Minor clock skew detected, event accepted',
                ];
            }

            return [
                'fresh' => false,
                'age_seconds' => $ageSeconds,
                'recommendation' => sprintf(
                    'Event timestamp is %d seconds in the future. Check NTP synchronization.',
                    $skewSeconds
                ),
            ];
        }

        if ($ageSeconds > $maxAgeSeconds) {
            return [
                'fresh' => false,
                'age_seconds' => $ageSeconds,
                'recommendation' => sprintf(
                    'Event is %d seconds old (max: %d). Possible replay attack or delayed delivery.',
                    $ageSeconds,
                    $maxAgeSeconds
                ),
            ];
        }

        return [
            'fresh' => true,
            'age_seconds' => $ageSeconds,
            'recommendation' => null,
        ];
    }

    /**
     * Build HTTP headers for webhook delivery.
     *
     * @param array $payload The webhook payload
     * @return array HTTP headers
     */
    public function buildHeaders(array $payload): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-CompliPay-Event-ID' => $payload['event_id'],
            'X-CompliPay-Event-Type' => $payload['event_type'],
            'X-CompliPay-Signature' => $payload['signature'],
            'X-CompliPay-Timestamp' => $payload['occurred_at'],
            'X-CompliPay-Idempotency-Key' => $payload['idempotency_key'],
            'User-Agent' => 'CompliPay-Webhook/1.0',
        ];
    }

    /**
     * Create an invoice cleared event payload.
     */
    public function invoiceCleared(
        string $invoiceId,
        string $organizationId,
        int $icv,
        string $clearanceId,
        string $webhookSecret
    ): array {
        return $this->build(
            self::EVENT_INVOICE_CLEARED,
            [
                'invoice_id' => $invoiceId,
                'organization_id' => $organizationId,
                'icv' => $icv,
                'clearance_id' => $clearanceId,
                'cleared_at' => now()->toIso8601String(),
            ],
            $webhookSecret
        );
    }

    /**
     * Create an invoice rejected event payload.
     */
    public function invoiceRejected(
        string $invoiceId,
        string $organizationId,
        int $icv,
        string $errorCode,
        string $errorMessage,
        string $webhookSecret
    ): array {
        return $this->build(
            self::EVENT_INVOICE_REJECTED,
            [
                'invoice_id' => $invoiceId,
                'organization_id' => $organizationId,
                'icv' => $icv,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'rejected_at' => now()->toIso8601String(),
            ],
            $webhookSecret
        );
    }

    /**
     * Create a certificate expiring event payload.
     */
    public function certificateExpiring(
        string $organizationId,
        string $certificateId,
        string $expiresAt,
        int $daysRemaining,
        string $webhookSecret
    ): array {
        return $this->build(
            self::EVENT_CERTIFICATE_EXPIRING,
            [
                'organization_id' => $organizationId,
                'certificate_id' => $certificateId,
                'expires_at' => $expiresAt,
                'days_remaining' => $daysRemaining,
                'action_required' => 'Renew certificate before expiration',
            ],
            $webhookSecret
        );
    }

    /**
     * Create a circuit breaker state change event payload.
     */
    public function circuitBreakerStateChange(
        string $service,
        string $previousState,
        string $newState,
        string $webhookSecret
    ): array {
        return $this->build(
            self::EVENT_CIRCUIT_BREAKER,
            [
                'service' => $service,
                'previous_state' => $previousState,
                'new_state' => $newState,
                'changed_at' => now()->toIso8601String(),
            ],
            $webhookSecret
        );
    }

    /**
     * Generate sample verification code for consumers.
     *
     * Returns example code that consumers can use to verify webhooks.
     */
    public function getVerificationExample(string $language = 'php'): string
    {
        if ($language === 'php') {
            return <<<'PHP'
<?php
// Verify CompliPay webhook
$payload = json_decode(file_get_contents('php://input'), true);
$signature = $_SERVER['HTTP_X_COMPLIPAY_SIGNATURE'] ?? '';
$secret = getenv('WEBHOOK_SECRET');

// Rebuild signature to compare
$dataToSign = implode('.', [
    $payload['event_id'],
    $payload['event_type'],
    $payload['occurred_at'],
    json_encode($payload['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$expectedSignature = 'sha256=' . hash_hmac('sha256', $dataToSign, $secret);

if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

// Check freshness (5 minute window)
$age = time() - strtotime($payload['occurred_at']);
if ($age > 300 || $age < -30) {
    http_response_code(400);
    exit('Event too old or future-dated');
}

// Check for replay
if (isEventProcessed($payload['event_id'])) {
    http_response_code(200);
    exit('Already processed');
}

// Process the event
processEvent($payload);
markEventProcessed($payload['event_id']);

http_response_code(200);
echo 'OK';
PHP;
        }

        if ($language === 'typescript') {
            return <<<'TS'
import crypto from 'crypto';

interface WebhookPayload {
  event_id: string;
  event_type: string;
  occurred_at: string;
  data: Record<string, unknown>;
  signature: string;
}

function verifyWebhook(payload: WebhookPayload, signature: string, secret: string): boolean {
  const dataToSign = [
    payload.event_id,
    payload.event_type,
    payload.occurred_at,
    JSON.stringify(payload.data),
  ].join('.');

  const expectedSignature = 'sha256=' + crypto
    .createHmac('sha256', secret)
    .update(dataToSign)
    .digest('hex');

  return crypto.timingSafeEqual(
    Buffer.from(expectedSignature),
    Buffer.from(signature)
  );
}

function checkFreshness(occurredAt: string, maxAgeSeconds = 300): boolean {
  const age = (Date.now() - new Date(occurredAt).getTime()) / 1000;
  return age >= -30 && age <= maxAgeSeconds;
}

// Usage in Express
app.post('/webhooks', (req, res) => {
  const payload = req.body;
  const signature = req.headers['x-complipay-signature'] as string;

  if (!verifyWebhook(payload, signature, process.env.WEBHOOK_SECRET!)) {
    return res.status(401).send('Invalid signature');
  }

  if (!checkFreshness(payload.occurred_at)) {
    return res.status(400).send('Event too old');
  }

  // Deduplicate and process
  if (await isProcessed(payload.event_id)) {
    return res.status(200).send('Already processed');
  }

  await processEvent(payload);
  await markProcessed(payload.event_id);

  res.status(200).send('OK');
});
TS;
        }

        return 'Unsupported language. Available: php, typescript';
    }

    /**
     * Get consumer contract/responsibilities.
     *
     * Returns structured documentation of what webhook consumers
     * MUST implement to ensure security and reliability.
     *
     * POLICY: Consumers are responsible for implementing these checks.
     *         CompliPay is not liable for security issues caused by
     *         consumers failing to verify signatures or check freshness.
     *
     * @return array Consumer contract documentation
     */
    public function getConsumerContract(): array
    {
        return [
            'version' => '1.0',
            'last_updated' => '2026-01-31',

            'mandatory_checks' => [
                [
                    'name' => 'Signature Verification',
                    'description' => 'Verify HMAC-SHA256 signature using webhook secret',
                    'header' => 'X-CompliPay-Signature',
                    'failure_action' => 'Return HTTP 401 and do not process event',
                    'security_level' => 'critical',
                ],
                [
                    'name' => 'Timestamp Freshness',
                    'description' => 'Reject events older than 5 minutes or more than 30 seconds in future',
                    'max_age_seconds' => self::MAX_EVENT_AGE_SECONDS,
                    'max_future_seconds' => 30,
                    'failure_action' => 'Return HTTP 400 and do not process event',
                    'security_level' => 'critical',
                ],
                [
                    'name' => 'Event Deduplication',
                    'description' => 'Track processed event_id values to prevent replay attacks',
                    'storage_recommendation' => 'Store event_id for at least 24 hours',
                    'failure_action' => 'Return HTTP 200 (already processed) without reprocessing',
                    'security_level' => 'high',
                ],
                [
                    'name' => 'Idempotent Processing',
                    'description' => 'Use idempotency_key to ensure safe retry handling',
                    'failure_action' => 'Ensure operations are idempotent',
                    'security_level' => 'medium',
                ],
            ],

            'response_requirements' => [
                'success' => [
                    'status_codes' => [200, 201, 202, 204],
                    'timeout' => '10 seconds',
                ],
                'retry_behavior' => [
                    'max_attempts' => 3,
                    'backoff' => 'exponential (5s, 25s, 125s)',
                    'retry_on' => ['5xx errors', 'timeout', 'connection failure'],
                    'no_retry_on' => ['4xx errors (except 429)'],
                ],
            ],

            'security_recommendations' => [
                'Use HTTPS endpoints only',
                'Rotate webhook secrets periodically',
                'Log all webhook processing for audit',
                'Implement rate limiting on webhook endpoint',
                'Use constant-time comparison for signatures',
            ],

            'liability_notice' => 'Consumers are solely responsible for implementing ' .
                'signature verification and replay protection. CompliPay is not liable ' .
                'for security incidents resulting from failure to implement these checks.',
        ];
    }
}
