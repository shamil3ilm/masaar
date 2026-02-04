<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ApiException extends Exception
{
    protected string $errorCode;
    protected ?array $details;
    protected int $httpStatus;

    public function __construct(
        array $error,
        ?array $details = null,
        ?string $customMessage = null,
        ?\Throwable $previous = null
    ) {
        $this->errorCode = $error['code'];
        $this->httpStatus = $error['http_status'];
        $this->details = $details;

        parent::__construct(
            $customMessage ?? $error['message'],
            $error['http_status'],
            $previous
        );
    }

    /**
     * Create exception from error code constant.
     */
    public static function fromError(array $error, ?array $details = null, ?string $customMessage = null): self
    {
        return new self($error, $details, $customMessage);
    }

    /**
     * Render the exception as HTTP response.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
            ],
        ], $this->httpStatus);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }
}
