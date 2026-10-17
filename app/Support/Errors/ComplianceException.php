<?php

declare(strict_types=1);

namespace App\Support\Errors;

use Exception;
use Throwable;

/**
 * A compliance failure carrying its place in the error taxonomy.
 *
 * Thrown by what every country passes through - the filing clearance, the
 * kill switch, the pipeline - and the base of each country's own compliance
 * exception, so a caller that catches this catches them all. The status,
 * retry advice and category all come off the ErrorCode.
 */
class ComplianceException extends Exception
{
    private ErrorCode $errorCode;

    private array $context;

    public function __construct(
        string $message,
        ErrorCode $errorCode,
        array $context = [],
        ?Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->context = $context;

        parent::__construct($message, $errorCode->getHttpStatus(), $previous);
    }

    /**
     * Get the error code enum.
     */
    public function getErrorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    /**
     * Check if this error is retryable.
     */
    public function isRetryable(): bool
    {
        return $this->errorCode->isRetryable();
    }

    /**
     * Get recommended retry delay in seconds.
     */
    public function getRetryDelay(): int
    {
        return $this->errorCode->getRetryDelay();
    }

    /**
     * Get maximum retry attempts.
     */
    public function getMaxRetries(): int
    {
        return $this->errorCode->getMaxRetries();
    }

    /**
     * Get HTTP status code for API response.
     */
    public function getHttpStatus(): int
    {
        return $this->errorCode->getHttpStatus();
    }

    /**
     * Alias for getHttpStatus() for consistency with exception handlers.
     */
    public function getHttpStatusCode(): int
    {
        return $this->getHttpStatus();
    }

    /**
     * Get error category.
     */
    public function getCategory(): string
    {
        return $this->errorCode->getCategory();
    }

    /**
     * Get additional context data.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Convert to API response array.
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode->value,
                'message' => $this->getMessage(),
                'category' => $this->errorCode->getCategory(),
                'retryable' => $this->isRetryable(),
                'retry_after' => $this->isRetryable() ? $this->getRetryDelay() : null,
                'max_retries' => $this->getMaxRetries(),
            ],
            'context' => $this->context ?: null,
        ];
    }
}
