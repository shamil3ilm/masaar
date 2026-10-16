<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\DTOs;

/**
 * ZATCA CSID API response wrapper.
 */
final readonly class CsidResponse
{
    public function __construct(
        public bool $success,
        public ?string $requestId,
        public ?string $binarySecurityToken,  // Base64-encoded certificate
        public ?string $secret,               // API secret for authentication
        public ?string $disposition,          // ISSUED, REVOKED
        public array $errorMessages,
        public ?string $rawResponse,
    ) {}

    /**
     * Create from ZATCA CSID API response.
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            success: isset($response['binarySecurityToken']) && isset($response['secret']),
            requestId: $response['requestID'] ?? null,
            binarySecurityToken: $response['binarySecurityToken'] ?? null,
            secret: $response['secret'] ?? null,
            disposition: $response['dispositionMessage'] ?? null,
            errorMessages: $response['errors'] ?? [],
            rawResponse: json_encode($response),
        );
    }

    /**
     * Create failed response.
     */
    public static function failed(string $error): self
    {
        return new self(
            success: false,
            requestId: null,
            binarySecurityToken: null,
            secret: null,
            disposition: null,
            errorMessages: [$error],
            rawResponse: null,
        );
    }
}
