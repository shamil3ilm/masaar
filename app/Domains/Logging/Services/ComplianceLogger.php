<?php

declare(strict_types=1);

namespace App\Domains\Logging\Services;

use App\Domains\Compliance\Fatoora\Helpers\LogSanitizer;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compliance Logger Service.
 *
 * Provides structured logging for ZATCA compliance operations with:
 * - Organization-separated log files
 * - Automatic fallback to system logs on failure
 * - Structured context for audit trails
 * - Log level management
 *
 * Usage:
 *   $logger = app(ComplianceLogger::class);
 *   $logger->forOrganization($orgId)->submission('Invoice submitted', [...]);
 */
class ComplianceLogger
{
    private ?string $organizationId = null;
    private ?string $userId = null;
    private array $defaultContext = [];

    /**
     * Log channels for different log types.
     */
    private const CHANNELS = [
        'submission' => 'zatca-submissions',
        'compliance' => 'zatca-compliance',
        'webhook' => 'zatca-webhooks',
        'audit' => 'zatca-audit',
        'error' => 'zatca-errors',
    ];

    /**
     * Fallback channel when primary fails.
     */
    private const FALLBACK_CHANNEL = 'daily';

    /**
     * Set organization context for subsequent logs.
     */
    public function forOrganization(string $organizationId): self
    {
        $clone = clone $this;
        $clone->organizationId = $organizationId;
        return $clone;
    }

    /**
     * Set user context for subsequent logs.
     */
    public function forUser(string $userId): self
    {
        $clone = clone $this;
        $clone->userId = $userId;
        return $clone;
    }

    /**
     * Add default context to all subsequent logs.
     */
    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->defaultContext = array_merge($clone->defaultContext, $context);
        return $clone;
    }

    /**
     * Log a submission event.
     */
    public function submission(string $message, array $context = []): void
    {
        $this->log('submission', 'info', $message, $context);
    }

    /**
     * Log a compliance event.
     */
    public function compliance(string $message, array $context = []): void
    {
        $this->log('compliance', 'info', $message, $context);
    }

    /**
     * Log a webhook event.
     */
    public function webhook(string $message, array $context = []): void
    {
        $this->log('webhook', 'info', $message, $context);
    }

    /**
     * Log an audit event.
     */
    public function audit(string $message, array $context = []): void
    {
        $this->log('audit', 'info', $message, $context);
    }

    /**
     * Log an error event.
     */
    public function error(string $message, array $context = [], ?Throwable $exception = null): void
    {
        if ($exception !== null) {
            $context['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        $this->log('error', 'error', $message, $context);
    }

    /**
     * Log a warning event.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('compliance', 'warning', $message, $context);
    }

    /**
     * Log a debug event.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('compliance', 'debug', $message, $context);
    }

    /**
     * Log an invoice state transition.
     */
    public function stateTransition(
        string $invoiceId,
        string $fromState,
        string $toState,
        array $context = []
    ): void {
        $this->audit('Invoice state transition', array_merge($context, [
            'invoice_id' => $invoiceId,
            'from_state' => $fromState,
            'to_state' => $toState,
            'transition_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * Log a ZATCA API call.
     */
    public function zatcaApiCall(
        string $endpoint,
        string $method,
        int $statusCode,
        ?float $duration = null,
        array $context = []
    ): void {
        $this->submission('ZATCA API call', array_merge($context, [
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $statusCode,
            'duration_ms' => $duration,
        ]));
    }

    /**
     * Log a webhook delivery.
     */
    public function webhookDelivery(
        string $webhookId,
        string $event,
        string $url,
        bool $success,
        ?int $statusCode = null,
        array $context = []
    ): void {
        $this->webhook('Webhook delivery', array_merge($context, [
            'webhook_id' => $webhookId,
            'event' => $event,
            'url' => $url,
            'success' => $success,
            'status_code' => $statusCode,
        ]));
    }

    /**
     * Core logging method with fallback support.
     */
    private function log(string $type, string $level, string $message, array $context): void
    {
        // Sanitised at the single choke point rather than at each call site,
        // so no caller can leak an API secret, private key or buyer detail by
        // forgetting to. Compliance logs are retained for years.
        $fullContext = LogSanitizer::sanitize($this->buildContext($context));
        $channel = self::CHANNELS[$type] ?? self::FALLBACK_CHANNEL;

        try {
            // Try primary channel
            $this->writeLog($channel, $level, $message, $fullContext);
        } catch (Throwable $e) {
            // Fallback to daily log
            try {
                $this->writeLog(self::FALLBACK_CHANNEL, $level, $message, array_merge($fullContext, [
                    'original_channel' => $channel,
                    'fallback_reason' => $e->getMessage(),
                ]));
            } catch (Throwable $fallbackException) {
                // Last resort: error_log
                error_log(sprintf(
                    '[ComplianceLogger] CRITICAL: Both primary and fallback logging failed. Message: %s, Context: %s',
                    $message,
                    json_encode($fullContext)
                ));
            }
        }
    }

    /**
     * Write to a specific log channel.
     */
    private function writeLog(string $channel, string $level, string $message, array $context): void
    {
        $logger = Log::channel($channel);

        match ($level) {
            'emergency' => $logger->emergency($message, $context),
            'alert' => $logger->alert($message, $context),
            'critical' => $logger->critical($message, $context),
            'error' => $logger->error($message, $context),
            'warning' => $logger->warning($message, $context),
            'notice' => $logger->notice($message, $context),
            'info' => $logger->info($message, $context),
            'debug' => $logger->debug($message, $context),
            default => $logger->info($message, $context),
        };
    }

    /**
     * Build the full context with organization and user info.
     */
    private function buildContext(array $context): array
    {
        $baseContext = [
            'timestamp' => now()->toIso8601String(),
            'request_id' => request()?->header('X-Request-ID') ?? uniqid('req_'),
        ];

        if ($this->organizationId !== null) {
            $baseContext['organization_id'] = $this->organizationId;
        }

        if ($this->userId !== null) {
            $baseContext['user_id'] = $this->userId;
        }

        return array_merge($baseContext, $this->defaultContext, $context);
    }

    /**
     * Create a logger instance from request context.
     */
    public static function fromRequest(): self
    {
        $logger = new self();

        // Try to get organization from request attributes
        $organizationId = request()?->attributes?->get('organization_id');
        if ($organizationId !== null) {
            $logger = $logger->forOrganization($organizationId);
        }

        // Try to get user from auth
        $userId = auth()->id();
        if ($userId !== null) {
            $logger = $logger->forUser((string) $userId);
        }

        return $logger;
    }
}
