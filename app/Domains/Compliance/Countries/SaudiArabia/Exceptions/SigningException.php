<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Exceptions;

use App\Http\Responses\ApiResponse;
use App\Support\Errors\RendersApiError;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Exception thrown during cryptographic signing operations.
 */
class SigningException extends Exception implements RendersApiError
{
    /**
     * 500, SIGNING_ERROR - not the 400 the handler once answered. Signing
     * happens entirely on this side, after the request has been accepted and
     * validated, so there is no request the caller could send that avoids
     * it. Telling them it was a bad request sends them to look at their own
     * payload for our defect.
     */
    public function apiResponse(): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), 500, [], 'SIGNING_ERROR', 'signing');
    }

    public function logApiError(): void
    {
        Log::error('Signing Exception', [
            'message' => $this->getMessage(),
        ]);
    }
}
