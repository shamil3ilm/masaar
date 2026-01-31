<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Exceptions;

use App\Domains\Compliance\Zatca\Enums\ErrorCode;
use Exception;
use Throwable;

/**
 * Base ZATCA exception with error taxonomy support.
 *
 * Provides structured error information for SDK consumers
 * including retry guidance and HTTP status mapping.
 */
class ZatcaException extends Exception
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
     * Get the error code string.
     */
    public function getErrorCodeValue(): string
    {
        return $this->errorCode->value;
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

    /**
     * Create exception from ZATCA error response.
     */
    public static function fromZatcaResponse(array $response): self
    {
        $zatcaCode = $response['errorCode'] ?? 'UNKNOWN';
        $errorCode = ErrorCode::fromZatcaError($zatcaCode);

        return new self(
            $response['errorMessage'] ?? $errorCode->getMessage(),
            $errorCode,
            [
                'zatca_code' => $zatcaCode,
                'validation_errors' => $response['validationResults']['errors'] ?? [],
                'warnings' => $response['validationResults']['warnings'] ?? [],
            ]
        );
    }

    /**
     * Create a validation exception.
     */
    public static function validation(string $message, ErrorCode $code = null, array $context = []): self
    {
        return new self(
            $message,
            $code ?? ErrorCode::VAL_INVALID_FORMAT,
            $context
        );
    }

    /**
     * Create a certificate exception.
     */
    public static function certificate(string $message, ErrorCode $code = null, array $context = []): self
    {
        return new self(
            $message,
            $code ?? ErrorCode::CERT_NOT_FOUND,
            $context
        );
    }

    /**
     * Create a network exception.
     */
    public static function network(string $message, ErrorCode $code = null, array $context = []): self
    {
        return new self(
            $message,
            $code ?? ErrorCode::NET_CONNECTION_FAILED,
            $context
        );
    }

    /**
     * Create an environment mismatch exception.
     *
     * Used when sandbox API keys attempt to access production resources.
     */
    public static function environmentMismatch(string $message, array $context = []): self
    {
        return new self(
            $message,
            ErrorCode::AUTH_ENVIRONMENT_MISMATCH,
            $context
        );
    }
}
