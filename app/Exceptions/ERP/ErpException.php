<?php

declare(strict_types=1);

namespace App\Exceptions\ERP;

use Exception;
use Illuminate\Http\JsonResponse;

abstract class ErpException extends Exception
{
    protected string $errorCode;
    protected array $context = [];
    protected int $httpStatus = 422;

    public function __construct(string $message = '', array $context = [], ?Exception $previous = null)
    {
        $this->context = $context;
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'context' => $this->context,
            ],
        ], $this->httpStatus);
    }
}
