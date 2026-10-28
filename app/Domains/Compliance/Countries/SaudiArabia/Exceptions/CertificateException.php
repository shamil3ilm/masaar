<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Exceptions;

use App\Http\Responses\ApiResponse;
use App\Support\Errors\RendersApiError;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Exception thrown during certificate operations.
 */
class CertificateException extends Exception implements RendersApiError
{
    /**
     * A certificate or credential failure: 422, CERTIFICATE_ERROR.
     *
     * One place decides the status, the code and the category, because seven
     * onboarding actions used to decide it themselves by catching the
     * exception and answering 422 with code VALIDATION_ERROR - describing an
     * expired or refused credential as a problem with the request body.
     *
     * 422, not the 400 the handler once answered. A certificate that is
     * expired, revoked or mismatched is a well-formed request refused by its
     * credential, which is what the taxonomy says too: every CERT_* content
     * failure is a 422 and the missing ones are 404. A 400 described it as
     * unreadable syntax.
     */
    public function apiResponse(): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), 422, [], 'CERTIFICATE_ERROR', 'certificate');
    }

    public function logApiError(): void
    {
        Log::error('Certificate Exception', [
            'message' => $this->getMessage(),
        ]);
    }
}
